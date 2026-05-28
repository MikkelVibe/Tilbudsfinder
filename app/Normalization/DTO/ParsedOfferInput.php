<?php

namespace App\Normalization\DTO;

use InvalidArgumentException;

readonly class ParsedOfferInput
{
    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed>|null $sourcePayload
     */
    public function __construct(
        public string $title,
        public string|int|float|null $price,
        public ?string $packageText = null,
        public string|int|float|null $sourceUnitPrice = null,
        public ?string $description = null,
        public ?string $imageUrl = null,
        public bool $isConditional = false,
        public ?string $purchaseLimitText = null,
        public array $metadata = [],
        public ?array $sourcePayload = null,
    ) {
        if (trim($title) === '') {
            throw new InvalidArgumentException('Parsed offer title is required.');
        }
    }

    public function cleanedTitle(): string
    {
        return preg_replace('/\s+/', ' ', trim($this->title)) ?? trim($this->title);
    }
}
