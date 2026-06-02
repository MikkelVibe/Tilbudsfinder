<?php

namespace App\Scrapers\Support;

use App\Scrapers\DTO\PaperCandidate;
use App\Scrapers\DTO\RawPaperPayload;
use App\Scrapers\Exceptions\ScraperFetchException;
use JsonException;

class KnownPaperPayload
{
    /**
     * @param  array{exists: bool, title?: ?string, active_from?: ?string, active_until?: ?string}  $knownPaper
     */
    public static function make(string $grocerKey, PaperCandidate $candidate, array $knownPaper): RawPaperPayload
    {
        try {
            $rawPayload = json_encode([
                'status' => 'already_fetched',
                'grocer' => $grocerKey,
                'source_external_id' => $candidate->sourceExternalId,
                'candidate' => [
                    'title' => $candidate->title,
                    'source_payload' => $candidate->sourcePayload,
                ],
                'known_paper' => $knownPaper,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new ScraperFetchException("Known paper proof for {$candidate->sourceExternalId} could not be encoded.", previous: $exception);
        }

        return new RawPaperPayload(
            sourceExternalId: $candidate->sourceExternalId,
            rawPayload: $rawPayload,
            title: $candidate->title,
            alreadyFetched: true,
        );
    }
}
