<?php

namespace App\Scrapers\DTO;

readonly class RawPaperPayload
{
    public function __construct(
        public string $sourceExternalId,
        public string $rawPayload,
        public ?string $title = null,
    ) {}
}
