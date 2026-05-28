<?php

namespace App\Normalization\DTO;

use App\Enums\NormalizationFailureSeverity;
use App\Normalization\Enums\NormalizationIssueCode;

readonly class NormalizationIssue
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public NormalizationIssueCode $code,
        public string $message,
        public NormalizationFailureSeverity $severity = NormalizationFailureSeverity::Warning,
        public ?string $field = null,
        public array $context = [],
    ) {
    }
}
