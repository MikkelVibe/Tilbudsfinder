<?php

namespace App\Scrapers\Rema1000\DTO;

readonly class RemaMatchResult
{
    /**
     * @param  list<array{tjek_offer: array<string, mixed>, rema_product: array<string, mixed>, advertised_price: array<string, mixed>, score: int}>  $matchedOffers
     * @param  list<array{code: string, source_catalog_id: string, source_offer_id: string, message: string, context: array<string, mixed>}>  $issues
     */
    public function __construct(
        public array $matchedOffers,
        public array $issues,
        public int $matchedTjekOfferCount,
        public int $ambiguousTjekOfferCount,
        public int $missingTjekOfferCount,
        public int $resolvedConflictCount,
    ) {}
}
