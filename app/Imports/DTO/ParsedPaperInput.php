<?php

namespace App\Imports\DTO;

use App\Normalization\DTO\ParsedOfferInput;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

readonly class ParsedPaperInput
{
    /**
     * @param  list<ParsedOfferInput>  $offers
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $sourceExternalId,
        public CarbonImmutable $activeFrom,
        public CarbonImmutable $activeUntil,
        public array $offers,
        public ?string $title = null,
        public ?string $sourceUrl = null,
        public ?string $rawPayload = null,
        public array $metadata = [],
    ) {
        if (trim($sourceExternalId) === '') {
            throw new InvalidArgumentException('Parsed paper source external ID is required.');
        }

        if ($activeFrom->greaterThanOrEqualTo($activeUntil)) {
            throw new InvalidArgumentException('Parsed paper active_from must be before active_until.');
        }
    }
}
