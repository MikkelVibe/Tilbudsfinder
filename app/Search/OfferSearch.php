<?php

namespace App\Search;

use App\Models\OfferSearchDocument;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OfferSearch
{
    public const SORT_RELEVANCE = 'relevance';

    public const SORT_PRICE_ASC = 'price_asc';

    public const SORT_PRICE_DESC = 'price_desc';

    public const SORT_NAME_ASC = 'name_asc';

    public const SORT_NAME_DESC = 'name_desc';

    /**
     * @param  list<string>  $grocerSlugs
     */
    public function search(?string $query, array $grocerSlugs, string $sort, int $perPage): LengthAwarePaginator
    {
        $queryText = trim((string) $query);

        $documents = OfferSearchDocument::query()
            ->where('active_from', '<=', now())
            ->where('active_until', '>=', now());

        if ($grocerSlugs !== []) {
            $documents->whereIn('grocer_slug', $grocerSlugs);
        }

        if ($queryText !== '') {
            $this->applyTextSearch($documents, $queryText);
        }

        $this->applySort($documents, $sort, $queryText !== '');

        return $documents->paginate($perPage)->withQueryString();
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
                "ts_rank_cd(search_vector, websearch_to_tsquery('simple', ?)) + (similarity(title, ?) * 0.25) + (similarity(search_text, ?) * 0.10) AS relevance_score",
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
     * @param  Builder<OfferSearchDocument>  $documents
     */
    private function applySort(Builder $documents, string $sort, bool $hasQuery): void
    {
        match ($sort) {
            self::SORT_PRICE_ASC => $documents->orderBy('price')->orderBy('title'),
            self::SORT_PRICE_DESC => $documents->orderByDesc('price')->orderBy('title'),
            self::SORT_NAME_ASC => $documents->orderBy('title')->orderBy('price'),
            self::SORT_NAME_DESC => $documents->orderByDesc('title')->orderBy('price'),
            default => $hasQuery
                ? $documents->orderByDesc('relevance_score')->orderBy('title')
                : $documents->orderByDesc('created_at')->orderBy('title'),
        };
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
