<?php

namespace App\Normalization\DTO;

use App\Normalization\Enums\CompareUnit;
use App\Normalization\Enums\NormalizedOfferStatus;
use App\Normalization\Enums\PackageUnit;
use App\Normalization\ValueObjects\Money;
use Brick\Math\BigDecimal;

readonly class NormalizedOffer
{
    /**
     * @param list<NormalizationIssue> $issues
     * @param array<string, mixed> $metadata
     * @param array<string, mixed>|null $sourcePayload
     */
    public function __construct(
        public NormalizedOfferStatus $status,
        public string $title,
        public ?Money $price,
        public ?BigDecimal $packageAmount,
        public ?PackageUnit $packageUnit,
        public ?string $packageUnitOriginal,
        public ?BigDecimal $normalizedAmount,
        public ?CompareUnit $compareUnit,
        public ?Money $sourceUnitPrice,
        public ?Money $calculatedUnitPrice,
        public ?Money $unitPrice,
        public int $confidence,
        public ?string $description = null,
        public ?string $imageUrl = null,
        public ?string $purchaseLimitText = null,
        public array $metadata = [],
        public ?array $sourcePayload = null,
        public array $issues = [],
    ) {
    }

    public function isPublishable(): bool
    {
        return $this->status !== NormalizedOfferStatus::Rejected;
    }
}
