<?php

namespace App\Scrapers\DTO;

readonly class ScraperPaperRunResult
{
    public function __construct(
        public string $sourceExternalId,
        public string $status,
        public ?string $message = null,
    ) {}

    public static function imported(string $sourceExternalId): self
    {
        return new self($sourceExternalId, 'imported');
    }

    public static function duplicate(string $sourceExternalId): self
    {
        return new self($sourceExternalId, 'duplicate');
    }

    public static function failed(string $sourceExternalId, string $message): self
    {
        return new self($sourceExternalId, 'failed', $message);
    }
}
