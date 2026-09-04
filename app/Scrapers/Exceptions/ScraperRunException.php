<?php

namespace App\Scrapers\Exceptions;

use App\Scrapers\DTO\ScraperRunResult;
use RuntimeException;
use Throwable;

class ScraperRunException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?ScraperRunResult $result = null,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
