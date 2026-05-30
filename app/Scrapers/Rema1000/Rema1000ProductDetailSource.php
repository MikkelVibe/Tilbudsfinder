<?php

namespace App\Scrapers\Rema1000;

use App\Scrapers\Exceptions\ScraperFetchException;
use App\Scrapers\Rema1000\DTO\Rema1000ProductDetail;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class Rema1000ProductDetailSource
{
    private const BASE_URL = 'https://api.digital.rema1000.dk/api';

    private const DELAY_MICROSECONDS = 1_000_000;

    private const JITTER_MICROSECONDS = 500_000;

    private const MINIMUM_COVERAGE_PERCENT = 95;

    public function __construct(
        private readonly bool $delayRequests = true,
    ) {}

    /**
     * @param  list<string>  $productIds
     * @return array<string, Rema1000ProductDetail>
     */
    public function details(array $productIds): array
    {
        $details = [];
        $failedProductIds = [];

        foreach ($productIds as $index => $productId) {
            if ($index > 0) {
                $this->delay();
            }

            try {
                $response = $this->http()
                    ->get(self::BASE_URL."/v3/products/{$productId}")
                    ->throw()
                    ->json();
            } catch (RequestException $exception) {
                if ($exception->response->serverError()) {
                    throw new ScraperFetchException(
                        "REMA 1000 product detail request failed for product [{$productId}] with upstream server error.",
                        previous: $exception,
                        context: ['grocer' => 'rema1000', 'product_id' => $productId, 'status' => $exception->response->status()],
                    );
                }

                $failedProductIds[] = $productId;

                continue;
            } catch (\Throwable $exception) {
                throw new ScraperFetchException(
                    "REMA 1000 product detail request failed for product [{$productId}].",
                    previous: $exception,
                    context: ['grocer' => 'rema1000', 'product_id' => $productId],
                );
            }

            $detail = Arr::get($response, 'data');
            $id = (string) Arr::get($detail, 'id', '');

            if (is_array($detail) && $id !== '') {
                $details[$id] = new Rema1000ProductDetail($detail);

                continue;
            }

            $failedProductIds[] = $productId;
        }

        $coverage = count($productIds) === 0 ? 0 : (count($details) / count($productIds)) * 100;

        if ($coverage < self::MINIMUM_COVERAGE_PERCENT) {
            throw new ScraperFetchException(
                'REMA 1000 product detail coverage was '.round($coverage, 2).'%, below '.self::MINIMUM_COVERAGE_PERCENT.'%. Failed products: '.implode(', ', array_slice($failedProductIds, 0, 10)),
                context: [
                    'grocer' => 'rema1000',
                    'coverage' => round($coverage, 2),
                    'minimum_coverage' => self::MINIMUM_COVERAGE_PERCENT,
                    'failed_product_ids' => array_slice($failedProductIds, 0, 10),
                ],
            );
        }

        return $details;
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->timeout(30)
            ->connectTimeout(5)
            ->withQueryParameters([
                'include' => 'prices',
            ])
            ->withHeaders([
                'X-Device' => 'web',
                'X-Timezone' => 'Copenhagen/Europe',
                'X-Locale' => 'da',
            ]);
    }

    private function delay(): void
    {
        if (! $this->delayRequests) {
            return;
        }

        usleep(self::DELAY_MICROSECONDS + random_int(0, self::JITTER_MICROSECONDS));
    }
}
