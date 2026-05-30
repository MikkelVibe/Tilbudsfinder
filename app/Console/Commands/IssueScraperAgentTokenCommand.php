<?php

namespace App\Console\Commands;

use App\Enums\ScraperAgentStatus;
use App\Models\ScraperAgent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('scraper-agent:token {agent : The scraper agent slug, e.g. apartment-laptop}')]
#[Description('Issue or rotate a scraper agent bearer token')]
class IssueScraperAgentTokenCommand extends Command
{
    public function handle(): int
    {
        $agentSlug = (string) $this->argument('agent');
        $token = Str::random(64);

        ScraperAgent::updateOrCreate(
            ['slug' => $agentSlug],
            [
                'name' => str($agentSlug)->replace('-', ' ')->title()->toString(),
                'token_hash' => hash('sha256', $token),
                'status' => ScraperAgentStatus::Active,
            ],
        );

        $this->info("Token issued for scraper agent [{$agentSlug}]. Store this value on the laptop; it will not be shown again.");
        $this->line($token);

        return self::SUCCESS;
    }
}
