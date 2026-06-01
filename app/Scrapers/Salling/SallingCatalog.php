<?php

namespace App\Scrapers\Salling;

use App\Scrapers\Exceptions\ScraperFetchException;

readonly class SallingCatalog
{
    public function __construct(
        public string $key,
        public string $name,
        public string $appId,
        public string $apiKey,
        public string $indexName,
        public string $filters,
    ) {}

    public static function forKey(string $key): self
    {
        return match ($key) {
            'bilkatogo' => new self(
                key: 'bilkatogo',
                name: 'BilkaToGo',
                appId: 'F9VBJLR1BK',
                apiKey: '1deaf41c87e729779f7695c00f190cc9',
                indexName: 'prod_BILKATOGO_PRODUCTS',
                filters: 'nonsearchable:false',
            ),
            'foetex' => new self(
                key: 'foetex',
                name: 'føtex',
                appId: 'DRP4O45G5T',
                apiKey: 'f3a34fc94874579eaf3cd39fef660948',
                indexName: 'prod_FOETEX_PRODUCTS',
                filters: 'is_exposed:true',
            ),
            default => throw new ScraperFetchException("Salling catalog [{$key}] is not supported."),
        };
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return ['bilkatogo', 'foetex'];
    }

    public function host(): string
    {
        return 'https://'.mb_strtolower($this->appId).'-dsn.algolia.net';
    }
}
