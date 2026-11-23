<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

final readonly class GroupProfileModel
{
    /**
     * @param array<int, float> $stemWeights by stem id
     */
    public function __construct(
        public array $stemWeights,
        public int $firstSeen,
        public int $lastSeen,
    ) {
    }

    public function computeSimilarity(self $other): float
    {
        $shared = 0.0;
        $combined = 0.0;
        foreach (array_keys($this->stemWeights + $other->stemWeights) as $stemId) {
            $ours = $this->stemWeights[$stemId] ?? 0.0;
            $theirs = $other->stemWeights[$stemId] ?? 0.0;
            $shared += min($ours, $theirs);
            $combined += max($ours, $theirs);
        }

        return $combined > 0.0 ? $shared / $combined : 0.0;
    }

    public function isWithinGap(self $other, int $maxGapSeconds): bool
    {
        return max($this->firstSeen, $other->firstSeen) - min($this->lastSeen, $other->lastSeen) <= $maxGapSeconds;
    }
}
