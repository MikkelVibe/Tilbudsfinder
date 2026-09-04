<?php

namespace App\Scrapers\Exceptions;

use RuntimeException;

class StaleScrapeJobAttemptException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Scrape job attempt is no longer current.');
    }
}
