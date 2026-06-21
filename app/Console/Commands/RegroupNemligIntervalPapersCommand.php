<?php

namespace App\Console\Commands;

use App\Models\ImportBatch;
use App\Models\NormalizationFailure;
use App\Models\OfferSearchDocument;
use App\Models\Paper;
use App\Models\PriceObservation;
use App\Models\ScrapedOffer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

#[Signature('nemlig:regroup-interval-papers {--execute : Regroup existing Nemlig papers instead of only reporting them}')]
#[Description('Regroup legacy Nemlig imports into one paper per visible campaign interval')]
class RegroupNemligIntervalPapersCommand extends Command
{
    public function handle(): int
    {
        $papers = $this->legacyPapers();
        $summary = $this->summary($papers);

        $this->line('Matched legacy Nemlig papers: '.$summary['papers']);
        $this->line('Visible interval offers to keep: '.$summary['visible_offers']);
        $this->line('Hidden or irrelevant interval offers to discard: '.$summary['hidden_offers']);
        $this->line('Invalid interval offers to discard: '.$summary['invalid_offers']);
        $this->line('Interval papers to create or reuse: '.$summary['interval_papers']);
        $this->line('Old papers that would become empty: '.$summary['old_empty_papers']);

        if (! $this->option('execute')) {
            $this->line('Dry run only. Re-run with --execute to regroup these records.');

            return self::SUCCESS;
        }

        $result = DB::transaction(fn (): array => $this->regroup($papers));

        $this->info('Regrouped legacy Nemlig papers: '.$result['papers']);
        $this->info('Moved visible interval offers: '.$result['moved_offers']);
        $this->info('Discarded hidden, invalid, irrelevant, or duplicate interval offers: '.$result['discarded_offers']);
        $this->info('Created interval papers: '.$result['created_papers']);
        $this->info('Deleted old empty papers: '.$result['deleted_papers']);
        $this->info('Deleted import batches with no remaining papers: '.$result['deleted_batches']);

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Paper>
     */
    private function legacyPapers(): Collection
    {
        return Paper::query()
            ->with(['grocer', 'importBatch', 'scrapedOffers'])
            ->whereHas('grocer', fn ($query) => $query->where('slug', 'nemlig'))
            ->whereHas('importBatch', fn ($query) => $query->where('metadata->source_strategy', 'nemlig_product_groups'))
            ->orderBy('created_at')
            ->get()
            ->reject(fn (Paper $paper): bool => (bool) preg_match('/^nemlig-\d{14}-\d{14}$/', $paper->source_external_id))
            ->values();
    }

    /**
     * @param  Collection<int, Paper>  $papers
     * @return array{papers: int, visible_offers: int, hidden_offers: int, invalid_offers: int, interval_papers: int, old_empty_papers: int}
     */
    private function summary(Collection $papers): array
    {
        $visibleOffers = 0;
        $hiddenOffers = 0;
        $invalidOffers = 0;
        $intervalPaperIds = collect();
        $oldEmptyPapers = $papers->count();

        foreach ($papers as $paper) {
            foreach ($paper->scrapedOffers as $offer) {
                $interval = $this->visibleInterval($offer);

                if ($interval === null) {
                    if ($this->hasVisibleCampaignInterval($offer) && $this->isRelevantProduct($offer)) {
                        $invalidOffers++;
                    } else {
                        $hiddenOffers++;
                    }

                    continue;
                }

                $visibleOffers++;
                $intervalPaperIds->push($this->intervalSourceExternalId($interval['active_from'], $interval['active_until']));
            }
        }

        return [
            'papers' => $papers->count(),
            'visible_offers' => $visibleOffers,
            'hidden_offers' => $hiddenOffers,
            'invalid_offers' => $invalidOffers,
            'interval_papers' => $intervalPaperIds->unique()->count(),
            'old_empty_papers' => $oldEmptyPapers,
        ];
    }

    /**
     * @param  Collection<int, Paper>  $papers
     * @return array{papers: int, moved_offers: int, discarded_offers: int, created_papers: int, deleted_papers: int, deleted_batches: int}
     */
    private function regroup(Collection $papers): array
    {
        $movedOffers = 0;
        $discardedOffers = 0;
        $createdPapers = 0;
        $deletedPapers = 0;
        $deletedBatches = collect();
        $touchedBatchIds = collect();
        $seenOfferKeysByPaper = collect();

        foreach ($papers as $paper) {
            $paper->loadMissing(['grocer', 'importBatch', 'scrapedOffers']);
            $batch = $paper->importBatch;

            foreach ($paper->scrapedOffers as $offer) {
                $interval = $this->visibleInterval($offer);

                if ($interval === null) {
                    $offer->delete();
                    $discardedOffers++;

                    continue;
                }

                [$intervalPaper, $wasCreated] = $this->intervalPaper($paper, $interval['active_from'], $interval['active_until']);
                $createdPapers += $wasCreated ? 1 : 0;
                $touchedBatchIds->push($intervalPaper->import_batch_id);

                $offerKey = $this->offerKey($offer);
                $seenOfferKeys = $this->seenOfferKeys($intervalPaper, $seenOfferKeysByPaper);

                if ($seenOfferKeys->has($offerKey)) {
                    $offer->delete();
                    $discardedOffers++;

                    continue;
                }

                $offer->update([
                    'paper_id' => $intervalPaper->id,
                    'import_batch_id' => $intervalPaper->import_batch_id,
                ]);

                OfferSearchDocument::query()
                    ->where('scraped_offer_id', $offer->id)
                    ->update([
                        'paper_id' => $intervalPaper->id,
                        'active_from' => $intervalPaper->active_from,
                        'active_until' => $intervalPaper->active_until,
                    ]);

                PriceObservation::query()
                    ->where('scraped_offer_id', $offer->id)
                    ->update([
                        'valid_from' => $intervalPaper->active_from,
                        'valid_until' => $intervalPaper->active_until,
                    ]);

                $seenOfferKeys->put($offerKey, true);
                $seenOfferKeysByPaper->put($intervalPaper->id, $seenOfferKeys);
                $movedOffers++;
            }

            if ($paper->scrapedOffers()->doesntExist()) {
                $paper->delete();
                $deletedPapers++;
            }

            if ($batch instanceof ImportBatch) {
                $touchedBatchIds->push($batch->id);
                $this->refreshBatchCounts($batch);

                if ($batch->papers()->doesntExist()) {
                    $path = $batch->raw_payload_path;
                    $batch->delete();

                    if ($path !== null) {
                        Storage::disk('local')->delete($path);
                    }

                    $deletedBatches->push($batch->id);
                }
            }
        }

        ImportBatch::query()
            ->whereIn('id', $touchedBatchIds->filter()->unique()->values())
            ->get()
            ->each(fn (ImportBatch $batch): mixed => $this->refreshBatchCounts($batch));

        return [
            'papers' => $papers->count(),
            'moved_offers' => $movedOffers,
            'discarded_offers' => $discardedOffers,
            'created_papers' => $createdPapers,
            'deleted_papers' => $deletedPapers,
            'deleted_batches' => $deletedBatches->unique()->count(),
        ];
    }

    /**
     * @return array{0: Paper, 1: bool}
     */
    private function intervalPaper(Paper $sourcePaper, CarbonImmutable $activeFrom, CarbonImmutable $activeUntil): array
    {
        $sourceExternalId = $this->intervalSourceExternalId($activeFrom, $activeUntil);
        $paper = Paper::query()
            ->where('grocer_id', $sourcePaper->grocer_id)
            ->where('source_external_id', $sourceExternalId)
            ->first();

        if ($paper instanceof Paper) {
            return [$paper, false];
        }

        return [Paper::create([
            'grocer_id' => $sourcePaper->grocer_id,
            'import_batch_id' => $sourcePaper->import_batch_id,
            'source_external_id' => $sourceExternalId,
            'title' => 'Nemlig tilbud '.$activeFrom->format('Y-m-d').' - '.$activeUntil->format('Y-m-d'),
            'active_from' => $activeFrom,
            'active_until' => $activeUntil,
        ]), true];
    }

    private function intervalSourceExternalId(CarbonImmutable $activeFrom, CarbonImmutable $activeUntil): string
    {
        return sprintf(
            'nemlig-%s-%s',
            $activeFrom->setTimezone('UTC')->format('YmdHis'),
            $activeUntil->setTimezone('UTC')->format('YmdHis'),
        );
    }

    /**
     * @return array{active_from: CarbonImmutable, active_until: CarbonImmutable}|null
     */
    private function visibleInterval(ScrapedOffer $offer): ?array
    {
        if (! $this->hasVisibleCampaignInterval($offer) || ! $this->isRelevantProduct($offer)) {
            return null;
        }

        $start = Arr::get($offer->source_payload, 'Campaign.IntervalStart');
        $end = Arr::get($offer->source_payload, 'Campaign.IntervalEnd');

        if (! is_string($start) || ! is_string($end) || trim($start) === '' || trim($end) === '') {
            return null;
        }

        try {
            $activeFrom = CarbonImmutable::parse($start);
            $activeUntil = CarbonImmutable::parse($end);
        } catch (InvalidArgumentException) {
            return null;
        }

        if ($activeFrom->greaterThanOrEqualTo($activeUntil)) {
            return null;
        }

        return [
            'active_from' => $activeFrom,
            'active_until' => $activeUntil,
        ];
    }

    private function hasVisibleCampaignInterval(ScrapedOffer $offer): bool
    {
        return Arr::get($offer->source_payload, 'Campaign.ShowCampaignInterval') === true;
    }

    private function isRelevantProduct(ScrapedOffer $offer): bool
    {
        $category = $this->normalizedText(Arr::get($offer->source_payload, 'Category'));
        $subcategory = $this->normalizedText(Arr::get($offer->source_payload, 'SubCategory'));

        if ($category === '') {
            return true;
        }

        return in_array($category, [
            'drikke',
            'frost',
            'grønt',
            'kiosk',
            'kolonial',
            'kød & fisk',
            'køl',
            'pleje',
            'vin',
        ], true)
            && ! in_array($subcategory, [
                'lingeri',
                'tobakstilbehør',
            ], true);
    }

    /**
     * @param  Collection<string, Collection<string, true>>  $seenOfferKeysByPaper
     * @return Collection<string, true>
     */
    private function seenOfferKeys(Paper $paper, Collection $seenOfferKeysByPaper): Collection
    {
        if ($seenOfferKeysByPaper->has($paper->id)) {
            return $seenOfferKeysByPaper->get($paper->id);
        }

        return $paper->scrapedOffers()
            ->get()
            ->mapWithKeys(fn (ScrapedOffer $offer): array => [$this->offerKey($offer) => true]);
    }

    private function offerKey(ScrapedOffer $offer): string
    {
        return implode('|', [
            $offer->source_product_id ?: $offer->source_offer_id ?: '',
            mb_strtolower(trim($offer->title)),
            $offer->price,
        ]);
    }

    private function normalizedText(mixed $value): string
    {
        return is_string($value) ? mb_strtolower(trim($value)) : '';
    }

    private function refreshBatchCounts(ImportBatch $batch): void
    {
        $batch->update([
            'parsed_offer_count' => $batch->scrapedOffers()->count(),
            'published_offer_count' => $batch->scrapedOffers()->count(),
            'normalization_failure_count' => NormalizationFailure::query()
                ->where('import_batch_id', $batch->id)
                ->count(),
        ]);
    }
}
