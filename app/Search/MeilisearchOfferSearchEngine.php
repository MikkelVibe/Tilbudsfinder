<?php

namespace App\Search;

use App\Models\OfferSearchDocument;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class MeilisearchOfferSearchEngine implements OfferSearchEngine
{
    public function search(OfferSearchQuery $query): LengthAwarePaginator
    {
        $page = Paginator::resolveCurrentPage();
        $payload = [
            'q' => trim((string) $query->query),
            'limit' => $query->perPage,
            'offset' => max(0, ($page - 1) * $query->perPage),
            'attributesToRetrieve' => ['id'],
            'filter' => $this->filters($query),
        ];

        if ($sort = $this->sort($query->sort, trim((string) $query->query) !== '')) {
            $payload['sort'] = [$sort];
        }

        $response = $this->request()->post($this->url('/indexes/'.$this->index().'/search'), $payload);

        if (! $response->successful()) {
            throw new RuntimeException('Meilisearch offer search failed with status '.$response->status());
        }

        $body = $response->json();
        $ids = collect($body['hits'] ?? [])
            ->pluck('id')
            ->filter()
            ->values()
            ->all();

        $documents = $this->documentsInHitOrder($ids);

        return new Paginator(
            $documents,
            (int) ($body['estimatedTotalHits'] ?? $body['totalHits'] ?? $documents->count()),
            $query->perPage,
            $page,
            [
                'path' => Paginator::resolveCurrentPath(),
                'query' => request()->query(),
            ],
        );
    }

    public function syncSettings(): void
    {
        $this->ensureIndexExists();

        $settings = [
            'searchableAttributes' => ['canonical_product_name', 'title', 'brand', 'category', 'subcategory', 'description', 'search_text'],
            'displayedAttributes' => ['id'],
            'filterableAttributes' => ['grocer_slug', 'canonical_product_id', 'active_from_timestamp', 'active_until_timestamp', 'price', 'unit_price', 'category', 'subcategory'],
            'sortableAttributes' => ['price', 'unit_price', 'title_sort', 'created_at_timestamp'],
            'rankingRules' => ['words', 'typo', 'proximity', 'attribute', 'sort', 'exactness'],
            'typoTolerance' => [
                'minWordSizeForTypos' => [
                    'oneTypo' => 5,
                    'twoTypos' => 9,
                ],
            ],
        ];

        $response = $this->request()->patch($this->url('/indexes/'.$this->index().'/settings'), $settings);

        if (! $response->successful()) {
            throw new RuntimeException('Meilisearch settings sync failed with status '.$response->status());
        }
    }

    /**
     * @param  iterable<OfferSearchDocument>  $documents
     */
    public function indexDocuments(iterable $documents): void
    {
        $this->ensureIndexExists();

        $payload = collect($documents)
            ->map(fn (OfferSearchDocument $document): array => $this->payloadForDocument($document))
            ->values()
            ->all();

        if ($payload === []) {
            return;
        }

        $response = $this->request()->post($this->url('/indexes/'.$this->index().'/documents'), $payload);

        if (! $response->successful()) {
            throw new RuntimeException('Meilisearch document sync failed with status '.$response->status());
        }
    }

    public function deleteAllDocuments(): void
    {
        $this->ensureIndexExists();

        $response = $this->request()->delete($this->url('/indexes/'.$this->index().'/documents'));

        if (! $response->successful() && $response->status() !== 404) {
            throw new RuntimeException('Meilisearch document flush failed with status '.$response->status());
        }
    }

    private function ensureIndexExists(): void
    {
        $response = $this->request()->put($this->url('/indexes/'.$this->index()), [
            'uid' => $this->index(),
            'primaryKey' => 'id',
        ]);

        if (! $response->successful() && $response->status() !== 409) {
            throw new RuntimeException('Meilisearch index creation failed with status '.$response->status());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadForDocument(OfferSearchDocument $document): array
    {
        return [
            'id' => $document->id,
            'scraped_offer_id' => $document->scraped_offer_id,
            'canonical_product_id' => $document->canonical_product_id,
            'canonical_product_name' => $document->canonical_product_name,
            'product_match_confidence' => $document->product_match_confidence,
            'grocer_slug' => $document->grocer_slug,
            'grocer_name' => $document->grocer_name,
            'title' => $document->title,
            'title_sort' => Str::lower($document->title),
            'brand' => $document->brand,
            'category' => $document->category,
            'subcategory' => $document->subcategory,
            'description' => $document->description,
            'search_text' => $document->search_text,
            'price' => (float) $document->price,
            'unit_price' => $document->unit_price === null ? null : (float) $document->unit_price,
            'active_from_timestamp' => $document->active_from?->getTimestamp(),
            'active_until_timestamp' => $document->active_until?->getTimestamp(),
            'created_at_timestamp' => $document->created_at?->getTimestamp(),
        ];
    }

    /**
     * @param  list<string>  $ids
     * @return Collection<int, OfferSearchDocument>
     */
    private function documentsInHitOrder(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        $documents = OfferSearchDocument::query()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        return collect($ids)
            ->map(fn (string $id): ?OfferSearchDocument => $documents->get($id))
            ->filter()
            ->values();
    }

    /**
     * @return list<string>
     */
    private function filters(OfferSearchQuery $query): array
    {
        $now = now()->getTimestamp();
        $filters = [
            'active_from_timestamp <= '.$now,
            'active_until_timestamp >= '.$now,
        ];

        if ($query->grocerSlugs !== []) {
            $quotedSlugs = collect($query->grocerSlugs)
                ->map(fn (string $slug): string => 'grocer_slug = "'.str_replace('"', '\"', $slug).'"')
                ->join(' OR ');

            $filters[] = '('.$quotedSlugs.')';
        }

        if ($query->priceMin !== null) {
            $filters[] = 'price >= '.$query->priceMin;
        }

        if ($query->priceMax !== null) {
            $filters[] = 'price <= '.$query->priceMax;
        }

        return $filters;
    }

    private function sort(string $sort, bool $hasQuery): ?string
    {
        return match ($sort) {
            DatabaseOfferSearchEngine::SORT_PRICE_ASC => 'price:asc',
            DatabaseOfferSearchEngine::SORT_PRICE_DESC => 'price:desc',
            DatabaseOfferSearchEngine::SORT_UNIT_PRICE_ASC => 'unit_price:asc',
            DatabaseOfferSearchEngine::SORT_NAME_ASC => 'title_sort:asc',
            DatabaseOfferSearchEngine::SORT_NAME_DESC => 'title_sort:desc',
            default => $hasQuery ? null : 'created_at_timestamp:desc',
        };
    }

    private function request(): PendingRequest
    {
        $request = Http::timeout((int) config('search.meilisearch.timeout', 2));
        $key = config('search.meilisearch.key');

        return filled($key) ? $request->withToken((string) $key) : $request;
    }

    private function index(): string
    {
        return (string) config('search.meilisearch.index', 'offers');
    }

    private function url(string $path): string
    {
        return rtrim((string) config('search.meilisearch.host'), '/').$path;
    }
}
