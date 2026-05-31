<?php

namespace App\Scrapers\Dagrofa;

class SparScraper extends DagrofaScraper
{
    public function __construct()
    {
        parent::__construct(DagrofaChain::spar());
    }
}
