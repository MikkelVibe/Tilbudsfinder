<?php

namespace App\Scrapers\Salling;

readonly class SallingCatalogProduct
{
    /**
     * @param  list<string>  $eans
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $source,
        public string $sourceProductId,
        public string $title,
        public array $eans,
        public ?string $brand,
        public ?string $packageText,
        public array $payload,
    ) {}
}
