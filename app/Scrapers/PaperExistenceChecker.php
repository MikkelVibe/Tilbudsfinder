<?php

namespace App\Scrapers;

use App\Models\Grocer;
use App\Models\Paper;

class PaperExistenceChecker
{
    /**
     * @param  list<string>  $sourceExternalIds
     * @return array<string, array{exists: bool, title?: ?string, active_from?: ?string, active_until?: ?string}>
     */
    public function check(string $grocerKey, array $sourceExternalIds): array
    {
        $ids = array_values(array_unique(array_filter($sourceExternalIds, fn (string $id): bool => trim($id) !== '')));

        if ($ids === []) {
            return [];
        }

        $grocer = Grocer::query()->where('slug', $grocerKey)->first();

        $result = array_fill_keys($ids, ['exists' => false]);

        if (! $grocer) {
            return $result;
        }

        Paper::query()
            ->where('grocer_id', $grocer->id)
            ->whereIn('source_external_id', $ids)
            ->get(['source_external_id', 'title', 'active_from', 'active_until'])
            ->each(function (Paper $paper) use (&$result): void {
                $result[$paper->source_external_id] = [
                    'exists' => true,
                    'title' => $paper->title,
                    'active_from' => $paper->active_from?->toIso8601String(),
                    'active_until' => $paper->active_until?->toIso8601String(),
                ];
            });

        return $result;
    }
}
