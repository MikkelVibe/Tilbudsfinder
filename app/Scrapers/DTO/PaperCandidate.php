<?php

namespace App\Scrapers\DTO;

readonly class PaperCandidate
{
    /**
     * @param  array<string, mixed>  $sourcePayload
     */
    public function __construct(
        public string $sourceExternalId,
        public ?string $title = null,
        public array $sourcePayload = [],
    ) {}
}
