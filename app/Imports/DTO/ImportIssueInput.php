<?php

namespace App\Imports\DTO;

use InvalidArgumentException;

readonly class ImportIssueInput
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public string $code,
        public string $message,
        public ?string $sourceCatalogId = null,
        public ?string $sourceOfferId = null,
        public array $context = [],
    ) {
        if (trim($code) === '') {
            throw new InvalidArgumentException('Import issue code is required.');
        }

        if (trim($message) === '') {
            throw new InvalidArgumentException('Import issue message is required.');
        }
    }
}
