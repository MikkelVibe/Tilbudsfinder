<?php

namespace App\Scrapers\Rema1000;

use App\Scrapers\Exceptions\ScraperFetchException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class RemaTjekClient
{
    private const API_URL = 'https://squid-api.tjek.com/v2';

    private const DEALER_ID = '11deC';

    /** @return list<array<string, mixed>> */
    public function activeWeeklyCatalogs(): array
    {
        $catalogs = [];
        $offset = 0;
        $pageSize = 100;

        do {
            $page = $this->list($this->http()->get(self::API_URL.'/catalogs', [
                'dealer_id' => self::DEALER_ID,
                'order_by' => '-publication_date',
                'offset' => $offset,
                'limit' => $pageSize,
                'types' => 'paged,incito',
            ])->throw()->json(), "Tjek catalog offset {$offset}");

            array_push($catalogs, ...$page);
            $offset += count($page);
        } while (count($page) === $pageSize);

        $now = CarbonImmutable::now();

        $weekly = array_values(array_filter($catalogs, function (array $catalog) use ($now): bool {
            $from = $this->date($catalog['run_from'] ?? null);
            $until = $this->date($catalog['run_till'] ?? null);

            return Str::contains((string) ($catalog['label'] ?? ''), 'Uge', ignoreCase: true)
                && $from !== null
                && $until !== null
                && $from->lessThanOrEqualTo($now)
                && $until->greaterThanOrEqualTo($now);
        }));

        if ($weekly === []) {
            throw new ScraperFetchException('REMA Tjek source returned no active weekly catalogs.');
        }

        return $weekly;
    }

    /**
     * @param  array<string, mixed>  $catalog
     * @return list<array<string, mixed>>
     */
    public function offers(array $catalog): array
    {
        $catalogId = trim((string) ($catalog['id'] ?? ''));
        $declaredCount = filter_var($catalog['offer_count'] ?? null, FILTER_VALIDATE_INT);

        if ($catalogId === '' || $declaredCount === false || $declaredCount < 0) {
            throw new ScraperFetchException('REMA Tjek catalog is missing an ID or valid offer count.');
        }

        $offers = [];
        $offset = 0;
        $pageSize = 100;

        do {
            $page = $this->list($this->http()->get(self::API_URL.'/offers', [
                'catalog_id' => $catalogId,
                'offset' => $offset,
                'limit' => $pageSize,
            ])->throw()->json(), "Tjek offers {$catalogId} offset {$offset}");

            foreach ($page as $offer) {
                $offerId = trim((string) ($offer['id'] ?? ''));

                if ($offerId === '') {
                    throw new ScraperFetchException("Tjek catalog {$catalogId} contains an offer without an ID.");
                }

                $offers[$offerId] = $offer;
            }

            $offset += count($page);
        } while (count($page) === $pageSize);

        if (count($offers) !== $declaredCount) {
            throw new ScraperFetchException("Tjek catalog {$catalogId} declares {$declaredCount} offers but returned ".count($offers).'.');
        }

        return array_values($offers);
    }

    /** @return list<array<string, mixed>> */
    private function list(mixed $value, string $context): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new ScraperFetchException("{$context} did not return a list.");
        }

        foreach ($value as $row) {
            if (! is_array($row)) {
                throw new ScraperFetchException("{$context} contains a non-object row.");
            }
        }

        return $value;
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && trim($value) !== '' ? CarbonImmutable::parse($value) : null;
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()
            ->connectTimeout(5)
            ->timeout(30)
            ->retry([250, 1000], static fn (Throwable $exception): bool => $exception instanceof ConnectionException
                || ($exception instanceof RequestException && $exception->response->serverError()));
    }
}
