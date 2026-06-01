<?php

namespace App\Console\Commands;

use App\Scrapers\Exceptions\ScraperFetchException;
use App\Scrapers\Salling\SallingCatalogClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('salling:catalog {catalog : Salling catalog key: bilkatogo or foetex} {--limit= : Limit fetched products for probing} {--json : Output EAN-backed products as JSON lines}')]
#[Description('Fetch public Salling Algolia product catalog rows and report EAN coverage')]
class FetchSallingCatalogCommand extends Command
{
    public function handle(SallingCatalogClient $client): int
    {
        try {
            $limit = $this->option('limit') === null ? null : (int) $this->option('limit');
            $result = $client->fetch((string) $this->argument('catalog'), $limit > 0 ? $limit : null);
        } catch (ScraperFetchException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Salling catalog [{$result->catalog->key}] fetched.");
        $this->line("Source: {$result->catalog->name}");
        $this->line("Index: {$result->catalog->indexName}");
        $this->line("Total hits: {$result->totalHits}");
        $this->line("Fetched products: {$result->fetchedHits}");
        $this->line('Products with EAN: '.$result->productsWithEanCount());
        $this->line('Unique EANs: '.$result->uniqueEanCount());

        if ((bool) $this->option('json')) {
            foreach ($result->products as $product) {
                if ($product->eans === []) {
                    continue;
                }

                $this->line(json_encode([
                    'source' => $product->source,
                    'source_product_id' => $product->sourceProductId,
                    'title' => $product->title,
                    'brand' => $product->brand,
                    'package_text' => $product->packageText,
                    'eans' => $product->eans,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        }

        return self::SUCCESS;
    }
}
