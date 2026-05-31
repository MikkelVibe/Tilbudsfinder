<?php

namespace App\Scrapers\Dagrofa;

class MenyScraper extends DagrofaScraper
{
    public function __construct()
    {
        parent::__construct(DagrofaChain::meny());
    }
}
