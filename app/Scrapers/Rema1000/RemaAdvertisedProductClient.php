<?php

namespace App\Scrapers\Rema1000;

use App\Scrapers\Exceptions\ScraperFetchException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Throwable;

class RemaAdvertisedProductClient
{
    private const URL = 'https://api.digital.rema1000.dk/api/search/products';

    /** @return list<array<string, mixed>> */
    public function fetch(?int $limit = null): array
    {
        $products = [];
        $page = 1;
        $lastPage = 1;

        do {
            $payload = $this->http()->get(self::URL, [
                'query' => '',
                'page' => $page,
                'per_page' => 1000,
                'filter' => ['is_advertised' => 1],
            ])->throw()->json();

            $rows = Arr::get($payload, 'data');
            $pagination = Arr::get($payload, 'meta.pagination');

            if (! is_array($rows) || ! array_is_list($rows) || ! is_array($pagination)) {
                throw new ScraperFetchException("REMA advertised product page {$page} has an invalid shape.");
            }

            foreach ($rows as $row) {
                if (! is_array($row) || ! is_scalar($row['id'] ?? null)) {
                    throw new ScraperFetchException("REMA advertised product page {$page} contains a product without an ID.");
                }

                $products[(string) $row['id']] = $row;

                if ($limit !== null && count($products) >= $limit) {
                    return array_values($products);
                }
            }

            $lastPage = filter_var($pagination['last_page'] ?? null, FILTER_VALIDATE_INT);

            if ($lastPage === false || $lastPage < 1) {
                throw new ScraperFetchException('REMA advertised product pagination is missing last_page.');
            }

            $page++;
        } while ($page <= $lastPage);

        $declaredTotal = filter_var(Arr::get($pagination, 'total'), FILTER_VALIDATE_INT);

        if ($products === [] || ($declaredTotal !== false && count($products) !== $declaredTotal)) {
            throw new ScraperFetchException('REMA advertised product pagination did not reconcile with its declared total.');
        }

        return array_values($products);
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()
            ->connectTimeout(5)
            ->timeout(30)
            ->withHeaders([
                'X-Device' => 'web',
                'X-Timezone' => 'Copenhagen/Europe',
                'X-Locale' => 'da',
            ])
            ->retry(
                [250, 1000],
                when: static fn (Throwable $exception): bool => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->serverError()),
            );
    }
}
