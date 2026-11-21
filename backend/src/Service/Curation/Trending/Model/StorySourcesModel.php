<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

/** A story's sources, counted as Trending counts them, and the posts sharing its articles. */
final readonly class StorySourcesModel
{
    public function __construct(
        public int $outletCount,
        public int $shareCount,
    ) {
    }
}
