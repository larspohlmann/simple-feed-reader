<?php

declare(strict_types=1);

namespace App\Dto\Entry;

/** "Mark this Trending story's list read" (#2329): the path names the story, so `until`, the moment the reader last
 * had the list on screen, is the whole request. */
final readonly class MarkTrendingStoryReadRequest
{
    public function __construct(
        public \DateTimeImmutable $until,
    ) {
    }
}
