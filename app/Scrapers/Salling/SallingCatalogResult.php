<?php

namespace App\Scrapers\Salling;

readonly class SallingCatalogResult
{
    /**
     * @param  list<SallingCatalogProduct>  $products
     */
    public function __construct(
        public SallingCatalog $catalog,
        public array $products,
        public int $totalHits,
        public int $fetchedHits,
    ) {}

    public function productsWithEanCount(): int
    {
        return count(array_filter($this->products, static fn (SallingCatalogProduct $product): bool => $product->eans !== []));
    }

    public function uniqueEanCount(): int
    {
        return count(array_unique(array_merge(...array_map(
            static fn (SallingCatalogProduct $product): array => $product->eans,
            $this->products,
        ))));
    }
}
