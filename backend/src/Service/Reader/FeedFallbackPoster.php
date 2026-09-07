<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Entity\Entry;

/**
 * The poster the reader falls back to for a video the fetched page offers
 * without a still of its own (#913). The feed already declared one for exactly
 * this entry: a `media[]` video's own `previewImageUrl`, or the lead image as
 * the generic still. No outbound HTTP — the URL is already persisted.
 */
final readonly class FeedFallbackPoster
{
    public function forEntry(Entry $entry): ?string
    {
        foreach ($entry->getMedia() as $medium) {
            if ($medium->kind === 'video' && $medium->previewImageUrl !== null) {
                return $medium->previewImageUrl;
            }
        }

        return $entry->getImageUrl();
    }
}
