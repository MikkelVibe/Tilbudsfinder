<?php

namespace App\Scrapers\Coop;

readonly class CoopBanner
{
    public function __construct(
        public string $key,
        public string $name,
        public string $dealerId,
        public string $sourceUrl,
    ) {}

    public static function kvickly(): self
    {
        return new self('kvickly', 'Kvickly', 'c1edq', 'https://kvickly.coop.dk/tilbudsavis/');
    }

    public static function superbrugsen(): self
    {
        return new self('superbrugsen', 'SuperBrugsen', '0b1e8', 'https://superbrugsen.coop.dk/tilbudsavis/');
    }

    public static function daglibrugsen(): self
    {
        return new self('daglibrugsen', "Dagli'Brugsen", 'd311fg', 'https://brugsen.coop.dk/tilbudsavis/');
    }

    public static function discount365(): self
    {
        return new self('365discount', '365discount', 'DWZE1w', 'https://365discount.coop.dk/tilbudsavis/');
    }
}
