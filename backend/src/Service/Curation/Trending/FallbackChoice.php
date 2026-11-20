<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending;

use App\Service\Curation\Trending\Model\TitleCandidateModel;

final readonly class FallbackChoice
{
    private const float STEADY_SHARE = 0.8;

    /** @param non-empty-list<TitleCandidateModel> $candidates */
    public function choose(array $candidates, ?int $currentEntryId): TitleCandidateModel
    {
        $best = $this->pickBest($candidates);
        $current = array_find(
            $candidates,
            static fn (TitleCandidateModel $candidate): bool => $candidate->entryId === $currentEntryId,
        );
        if ($current !== null && $this->staysAgainst($current, $best)) {
            return $current;
        }

        return $best;
    }

    /** @param non-empty-list<TitleCandidateModel> $candidates */
    private function pickBest(array $candidates): TitleCandidateModel
    {
        $markerFree = array_values(array_filter(
            $candidates,
            static fn (TitleCandidateModel $candidate): bool => $candidate->headline->markerFree,
        ));
        $pool = $markerFree === [] ? $candidates : $markerFree;

        return array_reduce(
            $pool,
            static fn (TitleCandidateModel $best, TitleCandidateModel $candidate): TitleCandidateModel
                => self::buildRank($candidate) > self::buildRank($best) ? $candidate : $best,
            $pool[0],
        );
    }

    private function staysAgainst(TitleCandidateModel $current, TitleCandidateModel $best): bool
    {
        return ($current->headline->markerFree || !$best->headline->markerFree)
            && $current->score >= self::STEADY_SHARE * $best->score;
    }

    /** @return array{float, int, int} */
    private static function buildRank(TitleCandidateModel $candidate): array
    {
        return [$candidate->score, $candidate->effectiveTime, $candidate->entryId];
    }
}
