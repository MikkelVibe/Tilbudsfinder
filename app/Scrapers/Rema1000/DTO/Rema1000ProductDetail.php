<?php

namespace App\Scrapers\Rema1000\DTO;

use Illuminate\Support\Arr;

readonly class Rema1000ProductDetail
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public array $payload,
    ) {}

    public function id(): ?string
    {
        $id = Arr::get($this->payload, 'id');

        if ((is_string($id) || is_int($id)) && trim((string) $id) !== '') {
            return (string) $id;
        }

        return null;
    }

    /**
     * @return list<Rema1000AdvertisedPrice>
     */
    public function advertisedPrices(): array
    {
        $prices = Arr::get($this->payload, 'prices');

        if (! is_array($prices)) {
            return [];
        }

        $advertisedPrices = [];

        foreach ($prices as $price) {
            if (! is_array($price)) {
                continue;
            }

            $advertisedPrice = new Rema1000AdvertisedPrice($price);

            if ($advertisedPrice->isUsable()) {
                $advertisedPrices[] = $advertisedPrice;
            }
        }

        return $advertisedPrices;
    }
}
