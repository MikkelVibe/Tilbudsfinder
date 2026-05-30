<?php

namespace App\Scrapers\Netto;

use App\Imports\DTO\ParsedPaperInput;
use App\Scrapers\DTO\RawPaperPayload;
use App\Scrapers\Exceptions\ScraperFetchException;
use App\Scrapers\GrocerScraper;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use JsonException;

class NettoScraper implements GrocerScraper
{
    private const TJEK_API_URL = 'https://squid-api.tjek.com/v2';

    private const TJEK_DEALER_ID = '9ba51';

    private const OFFERS_PAGE_SIZE = 100;

    public function __construct(
        private readonly NettoPaperParser $parser = new NettoPaperParser,
    ) {}

    public function grocerKey(): string
    {
        return 'netto';
    }

    /**
     * @return list<RawPaperPayload>
     */
    public function fetchPapers(?int $limit = null, ?callable $progress = null): array
    {
        $this->progress($progress, 'Fetching Netto Tjek catalogs...');
        $catalogs = $this->fetchActiveCatalogs();
        $this->progress($progress, 'Found '.count($catalogs).' active Netto catalogs.');

        $weeklyCatalogs = array_values(array_filter($catalogs, fn (array $catalog): bool => $this->isWeeklyCatalog($catalog)));
        $this->progress($progress, 'Found '.count($weeklyCatalogs).' active Netto Uge catalogs eligible for publishing.');

        if ($weeklyCatalogs === []) {
            throw new ScraperFetchException('Netto found no active Uge catalogs.');
        }

        return array_map(function (array $catalog) use ($limit, $progress): RawPaperPayload {
            $catalogId = $this->requiredString($catalog, 'id');

            $this->progress($progress, "Fetching Netto offers for catalog {$catalogId}...");
            $offers = $this->fetchCatalogOffers($catalogId, $limit);
            $this->progress($progress, 'Fetched '.count($offers)." Netto offers for catalog {$catalogId}.".($limit ? ' after limit' : ''));

            return $this->rawPayload($catalog, $offers);
        }, $weeklyCatalogs);
    }

    public function parse(RawPaperPayload $payload): ParsedPaperInput
    {
        return $this->parser->parse($payload->rawPayload);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchActiveCatalogs(): array
    {
        $catalogs = $this->tjekHttp()
            ->get(self::TJEK_API_URL.'/catalogs', [
                'dealer_id' => self::TJEK_DEALER_ID,
                'order_by' => '-publication_date',
                'offset' => 0,
                'limit' => 24,
                'types' => 'paged,incito',
            ])
            ->throw()
            ->json();

        if (! is_array($catalogs)) {
            throw new ScraperFetchException('Netto catalog response was not an array.');
        }

        $now = CarbonImmutable::now();

        return array_values(array_filter(array_filter($catalogs, 'is_array'), function (array $catalog) use ($now): bool {
            $offerCount = Arr::get($catalog, 'offer_count');
            $activeFrom = $this->date($catalog, 'run_from');
            $activeUntil = $this->date($catalog, 'run_till');

            return is_numeric($offerCount)
                && (int) $offerCount >= 10
                && $activeFrom !== null
                && $activeUntil !== null
                && $activeFrom->lessThanOrEqualTo($now)
                && $activeUntil->greaterThanOrEqualTo($now);
        }));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchCatalogOffers(string $catalogId, ?int $limit): array
    {
        $offers = [];
        $offset = 0;

        do {
            $pageLimit = $limit === null ? self::OFFERS_PAGE_SIZE : min(self::OFFERS_PAGE_SIZE, $limit - count($offers));

            if ($pageLimit <= 0) {
                break;
            }

            $page = $this->tjekHttp()
                ->get(self::TJEK_API_URL.'/offers', [
                    'catalog_id' => $catalogId,
                    'offset' => $offset,
                    'limit' => $pageLimit,
                ])
                ->throw()
                ->json();

            if (! is_array($page)) {
                throw new ScraperFetchException("Netto offers response for catalog {$catalogId} was not an array.");
            }

            $pageOffers = array_values(array_filter($page, 'is_array'));
            $offers = array_merge($offers, $pageOffers);
            $offset += count($pageOffers);
        } while (count($pageOffers) === $pageLimit && ($limit === null || count($offers) < $limit));

        if ($offers === []) {
            throw new ScraperFetchException("Netto catalog {$catalogId} returned no offers.");
        }

        return $offers;
    }

    /**
     * @param  array<string, mixed>  $catalog
     * @param  list<array<string, mixed>>  $offers
     */
    private function rawPayload(array $catalog, array $offers): RawPaperPayload
    {
        $catalogId = $this->requiredString($catalog, 'id');
        $rawPayload = $this->encode([
            'catalog' => [
                ...$catalog,
                'source_strategy' => 'tjek_weekly_catalog_offers',
                'fetched_offer_count' => count($offers),
                'offer_count_mismatch' => is_numeric(Arr::get($catalog, 'offer_count')) ? (int) Arr::get($catalog, 'offer_count') - count($offers) : null,
            ],
            'offers' => $offers,
        ]);

        return new RawPaperPayload(
            sourceExternalId: $catalogId,
            rawPayload: $rawPayload,
            title: $this->optionalString($catalog, 'label'),
        );
    }

    /**
     * @param  array<string, mixed>  $catalog
     */
    private function isWeeklyCatalog(array $catalog): bool
    {
        $label = $this->optionalString($catalog, 'label');

        return $label !== null && Str::contains($label, 'Uge', ignoreCase: true);
    }

    private function tjekHttp(): PendingRequest
    {
        return Http::acceptJson()
            ->timeout(20)
            ->connectTimeout(5)
            ->retry([200, 500]);
    }

    private function progress(?callable $progress, string $message): void
    {
        if ($progress !== null) {
            $progress($message);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function date(array $payload, string $key): ?CarbonImmutable
    {
        $value = Arr::get($payload, $key);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return CarbonImmutable::parse($value);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requiredString(array $payload, string $key): string
    {
        $value = Arr::get($payload, $key);

        if (! is_string($value) || trim($value) === '') {
            throw new ScraperFetchException("Netto source is missing {$key}.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function optionalString(array $payload, string $key): ?string
    {
        $value = Arr::get($payload, $key);

        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encode(array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new ScraperFetchException('Netto payload could not be encoded.', previous: $exception);
        }
    }
}
