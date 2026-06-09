<?php

namespace App\Scrapers\Dagrofa;

readonly class DagrofaChain
{
    public function __construct(
        public string $key,
        public string $name,
        public int $merchantId,
        public string $baseUrl,
        public string $sourceUrl,
        public ?string $iPaperUrl = null,
    ) {}

    public static function meny(): self
    {
        return new self(
            key: 'meny',
            name: 'MENY',
            merchantId: 558155,
            baseUrl: 'https://longjohnapi-meny.azurewebsites.net',
            sourceUrl: 'https://meny.dk/',
            iPaperUrl: 'https://ugensavis.meny.dk/',
        );
    }

    public static function spar(): self
    {
        return new self(
            key: 'spar',
            name: 'SPAR',
            merchantId: 1222,
            baseUrl: 'https://longjohnapi.azurewebsites.net',
            sourceUrl: 'https://spar.dk/',
            iPaperUrl: 'https://ugensavis.spar.dk/',
        );
    }

    public static function minKobmand(): self
    {
        return new self(
            key: 'minkobmand',
            name: 'Min Købmand',
            merchantId: 1302,
            baseUrl: 'https://longjohnapi.azurewebsites.net',
            sourceUrl: 'https://min-kobmand.dk/',
        );
    }
}
