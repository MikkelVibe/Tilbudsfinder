<?php

namespace App\Console\Commands;

use App\Models\Grocer;
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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

#[Signature('dagrofa:cleanup-synthetic-papers {--execute : Consolidate matched synthetic papers instead of only reporting them}')]
#[Description('Dry-run or consolidate synthetic one-day Dagrofa paper imports')]
class CleanupSyntheticDagrofaPapersCommand extends Command
{
    private const DAGROFA_SLUGS = ['meny', 'spar', 'minkobmand'];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $papers = $this->syntheticPapers();
        $groups = $this->paperGroups($papers);
        $keepers = $groups->map(fn (Collection $group): Paper => $this->keeper($group));
        $duplicatePapers = $groups->flatMap(fn (Collection $group): Collection => $group
            ->reject(fn (Paper $paper): bool => $paper->is($this->keeper($group)))
            ->values());
        $paperIds = $papers->pluck('id');
        $duplicateBatchIds = $duplicatePapers->pluck('import_batch_id')->filter()->unique()->values();
        $rawPayloadPaths = ImportBatch::query()
            ->whereIn('id', $duplicateBatchIds)
            ->pluck('raw_payload_path')
            ->filter()
            ->values();

        $this->line('Matched synthetic Dagrofa papers: '.$papers->count());
        $this->line('Inferred historical Dagrofa avis groups: '.$groups->count());
        $this->line('Representative papers to keep: '.$keepers->count());
        $this->line('Duplicate synthetic papers to delete: '.$duplicatePapers->count());
        $this->line('Duplicate import batches to delete: '.$duplicateBatchIds->count());
        $this->line('Matched scraped offers: '.$papers->sum('scraped_offers_count'));
        $this->line('Matched search documents: '.OfferSearchDocument::query()->whereIn('paper_id', $paperIds)->count());
        $this->line('Duplicate raw payload files to delete: '.$rawPayloadPaths->count());

        if (! $this->option('execute')) {
            $this->warn('Dry run only. Re-run with --execute to consolidate these records.');

            return self::SUCCESS;
        }

        $deletedBatchIds = collect();
        $deletedRawPayloadPaths = collect();

        DB::transaction(function () use ($groups, $duplicatePapers, $duplicateBatchIds, &$deletedBatchIds, &$deletedRawPayloadPaths): void {
            $groups->each(function (Collection $group): void {
                $this->consolidateGroup($group);
            });

            Paper::query()
                ->whereIn('id', $duplicatePapers->pluck('id'))
                ->delete();

            $deletedBatchIds = ImportBatch::query()
                ->whereIn('id', $duplicateBatchIds)
                ->whereDoesntHave('papers')
                ->pluck('id');

            $deletedRawPayloadPaths = ImportBatch::query()
                ->whereIn('id', $deletedBatchIds)
                ->pluck('raw_payload_path')
                ->filter()
                ->values();

            ImportBatch::query()
                ->whereIn('id', $deletedBatchIds)
                ->delete();
        });

        foreach ($deletedRawPayloadPaths as $path) {
            Storage::disk('local')->delete($path);
        }

        $this->info('Consolidated historical Dagrofa avis groups: '.$groups->count());
        $this->info('Deleted duplicate synthetic Dagrofa papers: '.$duplicatePapers->count());
        $this->info('Deleted import batches with no remaining papers: '.$deletedBatchIds->count());

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Paper>
     */
    private function syntheticPapers(): Collection
    {
        $grocers = Grocer::query()
            ->whereIn('slug', self::DAGROFA_SLUGS)
            ->get(['id', 'slug']);

        return $grocers
            ->flatMap(fn (Grocer $grocer): Collection => Paper::query()
                ->with(['grocer:id,slug', 'importBatch:id,metadata'])
                ->withCount('scrapedOffers')
                ->where('grocer_id', $grocer->id)
                ->where('source_external_id', 'like', $grocer->slug.'-%')
                ->get()
                ->filter(fn (Paper $paper): bool => $this->isSyntheticDagrofaPaper($paper, $grocer->slug)))
            ->values();
    }

    /**
     * @param  Collection<int, Paper>  $papers
     * @return Collection<string, Collection<int, Paper>>
     */
    private function paperGroups(Collection $papers): Collection
    {
        return $papers
            ->groupBy(fn (Paper $paper): string => $paper->grocer->slug.'|'.$this->dagrofaAvisPeriodStart($paper)->toDateString())
            ->map(fn (Collection $group): Collection => $group->sortBy('active_from')->values());
    }

    /**
     * @param  Collection<int, Paper>  $group
     */
    private function keeper(Collection $group): Paper
    {
        return $group
            ->sortByDesc(fn (Paper $paper): string => sprintf('%010d-%s', $paper->scraped_offers_count, $paper->active_from?->toIso8601String() ?? ''))
            ->firstOrFail();
    }

    /**
     * @param  Collection<int, Paper>  $group
     */
    private function consolidateGroup(Collection $group): void
    {
        $keeper = $this->keeper($group);
        $activeFrom = $group->min('active_from');
        $activeUntil = $group->max('active_until');
        $keeperBatch = $keeper->importBatch;

        if ($keeperBatch === null) {
            return;
        }

        $seenOfferKeys = $keeper->scrapedOffers()
            ->get()
            ->mapWithKeys(fn (ScrapedOffer $offer): array => [$this->offerKey($offer) => true]);

        $group
            ->reject(fn (Paper $paper): bool => $paper->is($keeper))
            ->each(function (Paper $paper) use ($keeper, $keeperBatch, $activeFrom, $activeUntil, $seenOfferKeys): void {
                $paper->scrapedOffers()
                    ->get()
                    ->each(function (ScrapedOffer $offer) use ($keeper, $keeperBatch, $activeFrom, $activeUntil, $seenOfferKeys): void {
                        $offerKey = $this->offerKey($offer);

                        if ($seenOfferKeys->has($offerKey)) {
                            $offer->delete();

                            return;
                        }

                        $offer->update([
                            'paper_id' => $keeper->id,
                            'import_batch_id' => $keeperBatch->id,
                        ]);

                        NormalizationFailure::query()
                            ->where('scraped_offer_id', $offer->id)
                            ->update(['import_batch_id' => $keeperBatch->id]);

                        OfferSearchDocument::query()
                            ->where('scraped_offer_id', $offer->id)
                            ->update([
                                'paper_id' => $keeper->id,
                                'active_from' => $activeFrom,
                                'active_until' => $activeUntil,
                            ]);

                        PriceObservation::query()
                            ->where('scraped_offer_id', $offer->id)
                            ->update([
                                'valid_from' => $activeFrom,
                                'valid_until' => $activeUntil,
                            ]);

                        $seenOfferKeys->put($offerKey, true);
                    });
            });

        $keeper->update([
            'active_from' => $activeFrom,
            'active_until' => $activeUntil,
        ]);

        OfferSearchDocument::query()
            ->where('paper_id', $keeper->id)
            ->update([
                'active_from' => $activeFrom,
                'active_until' => $activeUntil,
            ]);

        PriceObservation::query()
            ->whereIn('scraped_offer_id', $keeper->scrapedOffers()->pluck('id'))
            ->update([
                'valid_from' => $activeFrom,
                'valid_until' => $activeUntil,
            ]);

        $keeperBatch->update([
            'parsed_offer_count' => $keeper->scrapedOffers()->count(),
            'published_offer_count' => $keeper->scrapedOffers()->count(),
            'normalization_failure_count' => NormalizationFailure::query()->where('import_batch_id', $keeperBatch->id)->count(),
            'metadata' => [
                ...($keeperBatch->metadata ?? []),
                'synthetic_consolidated_from' => $group->pluck('source_external_id')->values()->all(),
                'synthetic_consolidated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    private function dagrofaAvisPeriodStart(Paper $paper): CarbonImmutable
    {
        $date = CarbonImmutable::parse($paper->active_from)->startOfDay();
        $daysSinceFriday = ($date->dayOfWeekIso - 5 + 7) % 7;

        return $date->subDays($daysSinceFriday);
    }

    private function offerKey(ScrapedOffer $offer): string
    {
        return implode('|', [
            $offer->source_product_id ?: $offer->source_offer_id ?: '',
            mb_strtolower(trim($offer->title)),
            $offer->price,
        ]);
    }

    private function isSyntheticDagrofaPaper(Paper $paper, string $slug): bool
    {
        if (preg_match('/^'.preg_quote($slug, '/').'-\d{4}-\d{2}-\d{2}$/', $paper->source_external_id) !== 1) {
            return false;
        }

        if ($paper->active_until === null || $paper->active_from === null || $paper->active_until->greaterThan($paper->active_from->addDay())) {
            return false;
        }

        return ($paper->importBatch?->metadata['source_strategy'] ?? null) === 'dagrofa_longjohn_discount_products';
    }
}
