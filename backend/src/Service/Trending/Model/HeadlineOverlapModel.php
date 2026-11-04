<?php

declare(strict_types=1);

namespace App\Service\Trending\Model;

final readonly class HeadlineOverlapModel
{
    public function __construct(
        public int $earlierEntryId,
        public int $entryId,
        public float $sharedWeight,
        public float $lighterTotal,
    ) {
    }

    public function shareOfLighter(): float
    {
        return $this->sharedWeight / $this->lighterTotal;
    }
}
