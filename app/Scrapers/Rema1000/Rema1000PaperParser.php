<?php

namespace App\Scrapers\Rema1000;

use App\Imports\DTO\ParsedPaperInput;
use App\Scrapers\Exceptions\ScraperParseException;
use App\Scrapers\Rema1000\DTO\Rema1000AdvertisedPrice;
use App\Scrapers\Rema1000\DTO\Rema1000Catalog;
use App\Scrapers\Rema1000\DTO\Rema1000ProductDetail;
use App\Scrapers\Rema1000\DTO\Rema1000ProductOffer;
use JsonException;

class Rema1000PaperParser
{
    public function __construct(
        private readonly Rema1000PaperMapper $mapper,
    ) {}

    public function parse(string $rawPayload): ParsedPaperInput
    {
        $payload = $this->decode($rawPayload);

        return $this->parsePayload($payload, $rawPayload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function parsePayload(array $payload, ?string $rawPayload = null): ParsedPaperInput
    {
        $catalog = $this->arrayValue($payload, 'catalog');
        $offers = $this->productOffers($this->arrayValue($payload, 'offers'));

        return $this->mapper->map(new Rema1000Catalog($catalog), $offers, $rawPayload);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $rawPayload): array
    {
        try {
            $payload = json_decode($rawPayload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ScraperParseException('REMA 1000 payload is not valid JSON.', previous: $exception);
        }

        if (! is_array($payload)) {
            throw new ScraperParseException('REMA 1000 payload must decode to an object.');
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    private function arrayValue(array $payload, string $key): array
    {
        $value = $payload[$key] ?? null;

        if (! is_array($value)) {
            throw new ScraperParseException("REMA 1000 payload is missing {$key}.");
        }

        return $value;
    }

    /**
     * @param  list<array<string, mixed>>  $offers
     * @return list<Rema1000ProductOffer>
     */
    private function productOffers(array $offers): array
    {
        $productOffers = [];

        foreach ($offers as $index => $offer) {
            if (! is_array($offer)) {
                throw new ScraperParseException("REMA 1000 offer at index {$index} must be an object.");
            }

            $productOffers[] = new Rema1000ProductOffer(
                algolia: $this->nestedArrayValue($offer, 'algolia'),
                productDetail: new Rema1000ProductDetail($this->nestedArrayValue($offer, 'product_detail')),
                advertisedPrice: new Rema1000AdvertisedPrice($this->nestedArrayValue($offer, 'advertised_price')),
            );
        }

        return $productOffers;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function nestedArrayValue(array $payload, string $key): array
    {
        $value = $payload[$key] ?? null;

        if (! is_array($value)) {
            throw new ScraperParseException("REMA 1000 payload is missing {$key}.");
        }

        return $value;
    }
}
