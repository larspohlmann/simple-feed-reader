<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

final readonly class StoryMergeModel
{
    public function __construct(
        public int $survivorId,
        public int $mergedId,
    ) {
    }
}
