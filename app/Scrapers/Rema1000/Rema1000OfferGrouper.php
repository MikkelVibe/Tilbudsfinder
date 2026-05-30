<?php

namespace App\Scrapers\Rema1000;

use App\Scrapers\Rema1000\DTO\Rema1000AdvertisedPrice;
use App\Scrapers\Rema1000\DTO\Rema1000Catalog;
use App\Scrapers\Rema1000\DTO\Rema1000ProductDetail;
use App\Scrapers\Rema1000\DTO\Rema1000ProductOffer;

class Rema1000OfferGrouper
{
    /**
     * @param  array<string, array<string, mixed>>  $products
     * @param  array<string, Rema1000ProductDetail>  $details
     * @param  list<Rema1000Catalog>  $catalogs
     * @return array<string, list<Rema1000ProductOffer>>
     */
    public function group(array $products, array $details, array $catalogs): array
    {
        $groups = [];

        foreach ($products as $productId => $product) {
            $detail = $details[$productId] ?? null;
            $match = $detail instanceof Rema1000ProductDetail ? $this->bestCatalogPriceMatch($detail, $catalogs) : null;

            if ($match === null) {
                continue;
            }

            [$catalog, $advertisedPrice] = $match;
            $catalogId = $catalog->requiredId();

            $groups[$catalogId] ??= [];
            $groups[$catalogId][] = new Rema1000ProductOffer($product, $detail, $advertisedPrice);
        }

        return $groups;
    }

    /**
     * @param  list<Rema1000Catalog>  $catalogs
     * @return array{Rema1000Catalog, Rema1000AdvertisedPrice}|null
     */
    private function bestCatalogPriceMatch(Rema1000ProductDetail $detail, array $catalogs): ?array
    {
        $bestMatch = null;
        $bestOverlap = 0;
        $bestCatalogDuration = PHP_INT_MAX;

        foreach ($detail->advertisedPrices() as $advertisedPrice) {
            foreach ($catalogs as $catalog) {
                $overlap = $advertisedPrice->overlapSeconds($catalog);
                $catalogDuration = $catalog->durationInSeconds();

                if ($overlap > $bestOverlap || ($overlap === $bestOverlap && $overlap > 0 && $catalogDuration < $bestCatalogDuration)) {
                    $bestMatch = [$catalog, $advertisedPrice];
                    $bestOverlap = $overlap;
                    $bestCatalogDuration = $catalogDuration;
                }
            }
        }

        return $bestMatch;
    }
}
