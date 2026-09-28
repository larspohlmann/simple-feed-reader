<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Backup\RestorePreview;

/**
 * The restore preview: the file's provenance, what it would load, and what
 * the account currently holds and would lose — so the client can show a
 * before/after instead of asking the user to trust a black box.
 */
final readonly class RestorePreviewJson
{
    /**
     * @return array<string, mixed>
     */
    public static function from(RestorePreview $preview): array
    {
        return [
            'backup' => [
                'backupId' => $preview->source->backupId,
                'parts' => $preview->source->parts,
                'createdAt' => $preview->source->createdAt->format(\DateTimeInterface::ATOM),
                'sourceUrl' => $preview->source->sourceUrl,
                'sourceEmail' => $preview->source->sourceEmail,
            ],
            'toLoad' => [
                'tags' => $preview->toLoad->tags,
                'savedSearches' => $preview->toLoad->savedSearches,
                'feeds' => $preview->toLoad->feeds,
                'subscriptions' => $preview->toLoad->subscriptions,
                'entries' => $preview->toLoad->entries,
                'entryStates' => $preview->toLoad->entryStates,
            ],
            'toDelete' => [
                'tags' => $preview->currentTags,
                'subscriptions' => $preview->currentSubscriptions,
                'entryStates' => $preview->currentEntryStates,
                'recommendationRuns' => $preview->currentRecommendationRuns,
            ],
        ];
    }
}
