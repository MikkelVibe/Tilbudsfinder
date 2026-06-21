<?php

namespace App\Search;

use App\Models\OfferSearchDocument;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DatabaseOfferSearchEngine implements OfferSearchEngine
{
    public const SORT_RELEVANCE = 'relevance';

    public const SORT_PRICE_ASC = 'price_asc';

    public const SORT_PRICE_DESC = 'price_desc';

    public const SORT_UNIT_PRICE_ASC = 'unit_price_asc';

    public const SORT_NAME_ASC = 'name_asc';

    public const SORT_NAME_DESC = 'name_desc';

    public function search(OfferSearchQuery $query): LengthAwarePaginator
    {
        $queryText = trim((string) $query->query);
        $hasQuery = $queryText !== '';

        $documents = OfferSearchDocument::query()
            ->where('active_from', '<=', now())
            ->where('active_until', '>=', now());

        if ($query->grocerSlugs !== []) {
            $documents->whereIn('grocer_slug', $query->grocerSlugs);
        }

        if ($query->priceMin !== null) {
            $documents->where('price', '>=', $query->priceMin->__toString());
        }

        if ($query->priceMax !== null) {
            $documents->where('price', '<=', $query->priceMax->__toString());
        }

        if ($hasQuery) {
            $this->applyTextSearch($documents, $queryText);
        }

        return $this->paginateProductGroups($documents, $query, $hasQuery);
    }

    /**
     * @param  Builder<OfferSearchDocument>  $documents
     */
    private function paginateProductGroups(Builder $documents, OfferSearchQuery $query, bool $hasQuery): LengthAwarePaginator
    {
        $page = Paginator::resolveCurrentPage();
        $groupExpression = $this->productGroupExpression('filtered');
        $orderExpression = $this->groupRepresentativeOrderExpression($query->sort, $hasQuery);

        $ranked = DB::query()
            ->fromSub((clone $documents)->toBase(), 'filtered')
            ->select([
                'filtered.id',
                'filtered.price',
                'filtered.unit_price',
                'filtered.title',
                'filtered.created_at',
            ])
            ->selectRaw($groupExpression.' as product_group_key')
            ->selectRaw($this->displayTitleExpression('filtered').' as display_title')
            ->selectRaw($hasQuery ? 'coalesce(filtered.relevance_score, 0) as relevance_score' : '0 as relevance_score')
            ->selectRaw("row_number() over (partition by {$groupExpression} order by {$orderExpression}) as product_rank");

        $groups = DB::query()
            ->fromSub($ranked, 'ranked')
            ->where('ranked.product_rank', 1);

        $total = (clone $groups)->count();

        $rows = $groups
            ->select([
                'ranked.id',
                'ranked.product_group_key',
                'ranked.relevance_score',
            ])
            ->orderByRaw($this->productGroupOrderExpression($query->sort, $hasQuery))
            ->forPage($page, $query->perPage)
            ->get();

        $documents = $this->documentsForGroupedRows($rows, $this->statsForGroupedRows($documents, $rows));

        return new Paginator(
            $documents,
            $total,
            $query->perPage,
            $page,
            [
                'path' => Paginator::resolveCurrentPath(),
                'query' => request()->query(),
            ],
        );
    }

    private function productGroupExpression(string $alias): string
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            return "coalesce('canonical_' || {$alias}.canonical_product_id::text, 'scraped_' || {$alias}.scraped_offer_id::text)";
        }

        return "coalesce(concat('canonical_', {$alias}.canonical_product_id), concat('scraped_', {$alias}.scraped_offer_id))";
    }

    private function displayTitleExpression(string $alias): string
    {
        return "coalesce({$alias}.canonical_product_name, {$alias}.title)";
    }

    private function groupRepresentativeOrderExpression(string $sort, bool $hasQuery): string
    {
        $displayTitleExpression = $this->displayTitleExpression('filtered');

        return match ($sort) {
            self::SORT_PRICE_ASC => 'filtered.price asc, filtered.title asc, filtered.id asc',
            self::SORT_PRICE_DESC => 'filtered.price desc, filtered.title asc, filtered.id asc',
            self::SORT_UNIT_PRICE_ASC => 'filtered.unit_price is null, filtered.unit_price asc, filtered.price asc, filtered.title asc, filtered.id asc',
            self::SORT_NAME_ASC => "{$displayTitleExpression} asc, filtered.price asc, filtered.id asc",
            self::SORT_NAME_DESC => "{$displayTitleExpression} desc, filtered.price asc, filtered.id asc",
            default => $hasQuery
                ? 'relevance_score desc, filtered.price asc, filtered.title asc, filtered.id asc'
                : 'filtered.created_at desc, filtered.price asc, filtered.title asc, filtered.id asc',
        };
    }

    private function productGroupOrderExpression(string $sort, bool $hasQuery): string
    {
        return match ($sort) {
            self::SORT_PRICE_ASC => 'ranked.price asc, ranked.title asc, ranked.id asc',
            self::SORT_PRICE_DESC => 'ranked.price desc, ranked.title asc, ranked.id asc',
            self::SORT_UNIT_PRICE_ASC => 'ranked.unit_price is null, ranked.unit_price asc, ranked.price asc, ranked.title asc, ranked.id asc',
            self::SORT_NAME_ASC => 'ranked.display_title asc, ranked.price asc, ranked.id asc',
            self::SORT_NAME_DESC => 'ranked.display_title desc, ranked.price asc, ranked.id asc',
            default => $hasQuery
                ? 'ranked.relevance_score desc, ranked.price asc, ranked.title asc, ranked.id asc'
                : 'ranked.created_at desc, ranked.price asc, ranked.title asc, ranked.id asc',
        };
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return Collection<string, object>
     */
    private function statsForGroupedRows(Builder $documents, Collection $rows): Collection
    {
        if ($rows->isEmpty()) {
            return collect();
        }

        $groupExpression = $this->productGroupExpression('filtered');

        return DB::query()
            ->fromSub((clone $documents)->toBase(), 'filtered')
            ->selectRaw($groupExpression.' as product_group_key')
            ->selectRaw('count(*) as product_offer_count')
            ->selectRaw('count(distinct filtered.grocer_slug) as product_store_count')
            ->whereIn(DB::raw($groupExpression), $rows->pluck('product_group_key')->all())
            ->groupBy('product_group_key')
            ->get()
            ->keyBy('product_group_key');
    }

    /**
     * @param  Collection<int, object>  $rows
     * @param  Collection<string, object>  $stats
     * @return Collection<int, OfferSearchDocument>
     */
    private function documentsForGroupedRows(Collection $rows, Collection $stats): Collection
    {
        if ($rows->isEmpty()) {
            return collect();
        }

        $documents = OfferSearchDocument::query()
            ->whereIn('id', $rows->pluck('id')->all())
            ->get()
            ->keyBy('id');

        return $rows
            ->map(function (object $row) use ($documents, $stats): ?OfferSearchDocument {
                /** @var OfferSearchDocument|null $document */
                $document = $documents->get($row->id);

                if ($document === null) {
                    return null;
                }

                $document->product_group_key = $row->product_group_key;
                $document->relevance_score = $row->relevance_score;
                $document->product_offer_count = (int) ($stats->get($row->product_group_key)?->product_offer_count ?? 1);
                $document->product_store_count = (int) ($stats->get($row->product_group_key)?->product_store_count ?? 1);

                return $document;
            })
            ->filter()
            ->values();
    }

    /**
     * @param  Builder<OfferSearchDocument>  $documents
     */
    private function applyTextSearch(Builder $documents, string $queryText): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $likes = $this->likeTerms($queryText);

            $documents
                ->select('offer_search_documents.*')
                ->selectRaw('CASE WHEN lower(title) LIKE ? THEN 1 ELSE 0 END AS relevance_score', [$likes[0]])
                ->where(function (Builder $documents) use ($likes): void {
                    foreach ($likes as $like) {
                        $documents->whereRaw('lower(search_text) LIKE ?', [$like]);
                    }
                });

            return;
        }

        $likes = $this->likeTerms($queryText);

        $documents
            ->select('offer_search_documents.*')
            ->selectRaw(
                "ts_rank_cd(search_vector, websearch_to_tsquery('simple', ?)) + (similarity(title, ?) * 0.35) + (similarity(search_text, ?) * 0.10) AS relevance_score",
                [$queryText, $queryText, $queryText],
            )
            ->where(function (Builder $documents) use ($likes, $queryText): void {
                $documents
                    ->whereRaw("search_vector @@ websearch_to_tsquery('simple', ?)", [$queryText])
                    ->orWhereRaw('search_text % ?', [$queryText])
                    ->orWhere(function (Builder $documents) use ($likes): void {
                        foreach ($likes as $like) {
                            $documents->whereRaw('search_text ILIKE ?', [$like]);
                        }
                    });
            });
    }

    /**
     * @return non-empty-list<string>
     */
    private function likeTerms(string $queryText): array
    {
        $terms = Str::of($queryText)
            ->lower()
            ->squish()
            ->explode(' ')
            ->map(static fn (string $term): string => trim($term))
            ->filter(static fn (string $term): bool => $term !== '')
            ->map(static fn (string $term): string => '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%')
            ->values()
            ->all();

        return $terms === [] ? ['%'] : $terms;
    }
}
