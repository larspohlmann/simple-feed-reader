<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Support;

use App\Service\Curation\Trending\Pass\HeadlineWindow;

final class CentroidScores
{
    /**
     * Each member's score is the weight it shares with every other member.
     *
     * @param list<int> $memberIds
     *
     * @return array<int, float> by member id
     */
    public static function of(HeadlineWindow $headlines, array $memberIds): array
    {
        $scores = array_fill_keys($memberIds, 0.0);
        foreach ($memberIds as $entryId) {
            $sharedWithMembers = array_intersect_key($headlines->sharedWeightsWithEarlier($entryId), $scores);
            foreach ($sharedWithMembers as $earlierId => $weight) {
                $scores[$entryId] += $weight;
                $scores[$earlierId] += $weight;
            }
        }

        return $scores;
    }

    private function __construct()
    {
    }
}
