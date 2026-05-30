<?php

namespace App\Scrapers\Rema1000;

use App\Imports\DTO\ParsedPaperInput;
use App\Normalization\DTO\ParsedOfferInput;
use App\Scrapers\Exceptions\ScraperFetchException;
use App\Scrapers\Exceptions\ScraperParseException;
use App\Scrapers\Rema1000\DTO\Rema1000Catalog;
use App\Scrapers\Rema1000\DTO\Rema1000ProductOffer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use JsonException;

class Rema1000PaperMapper
{
    /**
     * @param  list<Rema1000ProductOffer>  $offers
     */
    public function map(Rema1000Catalog $catalog, array $offers, ?string $rawPayload = null): ParsedPaperInput
    {
        $payload = $this->payload($catalog, $offers);

        return new ParsedPaperInput(
            sourceExternalId: $this->requiredCatalogId($catalog),
            activeFrom: $this->requiredCatalogDate($catalog->activeFrom(), 'run_from'),
            activeUntil: $this->requiredCatalogDate($catalog->activeUntil(), 'run_till'),
            offers: $this->parseOffers($offers),
            title: $catalog->label(),
            sourceUrl: 'https://shop.rema1000.dk/avisvarer',
            rawPayload: $rawPayload ?? $this->encode($payload),
            metadata: array_filter([
                'dealer_id' => $this->optionalString($catalog->payload, 'dealer_id'),
                'dealer_name' => $this->optionalString($catalog->payload, 'dealer.name'),
                'offer_count' => Arr::get($catalog->payload, 'offer_count'),
                'fetched_offer_count' => Arr::get($catalog->payload, 'fetched_offer_count'),
                'fetched_product_offer_count' => count($offers),
                'offer_count_mismatch' => Arr::get($catalog->payload, 'offer_count_mismatch'),
                'page_count' => Arr::get($catalog->payload, 'page_count'),
                'pdf_url' => $this->optionalString($catalog->payload, 'pdf_url'),
                'source_strategy' => 'algolia_product_details_grouped_by_tjek_overlap',
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    /**
     * @param  list<Rema1000ProductOffer>  $offers
     * @return array<string, mixed>
     */
    private function payload(Rema1000Catalog $catalog, array $offers): array
    {
        return [
            'catalog' => [
                ...$catalog->payload,
                'source_strategy' => 'algolia_product_details_grouped_by_tjek_overlap',
                'fetched_product_offer_count' => count($offers),
            ],
            'offers' => array_map(fn (Rema1000ProductOffer $offer): array => $offer->sourcePayload(), $offers),
        ];
    }

    /**
     * @param  list<Rema1000ProductOffer>  $offers
     * @return list<ParsedOfferInput>
     */
    private function parseOffers(array $offers): array
    {
        $parsedOffers = [];

        foreach ($offers as $offer) {
            $parsedOffers[] = $this->parseProductOffer($offer);
        }

        return $parsedOffers;
    }

    private function parseProductOffer(Rema1000ProductOffer $offer): ParsedOfferInput
    {
        return new ParsedOfferInput(
            title: $this->requiredOfferTitle($offer),
            price: $offer->price(),
            packageText: $offer->packageText(),
            sourceUnitPrice: $offer->sourceUnitPrice(),
            description: $offer->description(),
            imageUrl: $offer->imageUrl(),
            sourceOfferId: $offer->sourceOfferId(),
            sourceProductId: $offer->sourceProductId(),
            purchaseLimitText: $offer->purchaseLimitText(),
            metadata: $offer->metadata(),
            sourcePayload: $offer->sourcePayload(),
        );
    }

    private function requiredOfferTitle(Rema1000ProductOffer $offer): string
    {
        $title = $offer->title();

        if ($title === null) {
            throw new ScraperParseException('REMA 1000 payload is missing name.');
        }

        return $title;
    }

    private function requiredCatalogId(Rema1000Catalog $catalog): string
    {
        $id = $catalog->id();

        if ($id === null) {
            throw new ScraperParseException('REMA 1000 payload is missing id.');
        }

        return $id;
    }

    private function requiredCatalogDate(?CarbonImmutable $date, string $key): CarbonImmutable
    {
        if ($date === null) {
            throw new ScraperParseException("REMA 1000 payload is missing {$key}.");
        }

        return $date;
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
            throw new ScraperFetchException('REMA 1000 payload could not be encoded.', previous: $exception);
        }
    }
}
