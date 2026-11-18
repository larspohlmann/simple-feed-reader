<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

final readonly class TitleCandidateModel
{
    /** @param int $effectiveTime Unix seconds */
    public function __construct(
        public int $entryId,
        public CleanedHeadlineModel $headline,
        public float $score,
        public int $effectiveTime,
    ) {
    }
}
