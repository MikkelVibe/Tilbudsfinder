<?php

namespace App\Scrapers\Dagrofa;

class MinKobmandScraper extends DagrofaScraper
{
    public function __construct()
    {
        parent::__construct(DagrofaChain::minKobmand());
    }
}
