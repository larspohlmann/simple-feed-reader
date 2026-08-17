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
                'createdAt' => $preview->header->createdAt->format(\DateTimeInterface::ATOM),
                'sourceUrl' => $preview->header->sourceUrl,
                'sourceEmail' => $preview->header->sourceEmail,
            ],
            'toLoad' => [
                'tags' => $preview->toLoad->tags,
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
