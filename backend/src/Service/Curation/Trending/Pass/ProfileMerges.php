<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Pass;

use App\Service\Curation\Trending\Model\GroupProfileModel;
use App\Service\Curation\Trending\Support\GroupProfiles;

/**
 * Story groups whose headline profiles are alike merge, most alike first, one merge per group a round, until a
 * round merges nothing, so one news event that the links split into several groups becomes one story (#2490).
 */
final class ProfileMerges
{
    private const float MERGE_SIMILARITY = 0.30;
    private const int MIN_MEMBERS = 3;

    /** @var array<int, non-empty-list<int>> lowest member => members ascending */
    private array $groups = [];

    /** @var array<int, GroupProfileModel> lowest member => profile, only groups of at least MIN_MEMBERS */
    private array $profiles = [];

    /** @param list<non-empty-list<int>> $groups */
    public function __construct(private readonly TrendingRun $run, array $groups)
    {
        foreach ($groups as $members) {
            sort($members);
            $this->groups[$members[0]] = $members;
        }
        ksort($this->groups);
        foreach ($this->groups as $groupKey => $members) {
            if (\count($members) >= self::MIN_MEMBERS) {
                $this->profiles[$groupKey] = GroupProfiles::build($this->run, $members);
            }
        }
    }

    /** @return list<non-empty-list<int>> members ascending, groups by their lowest member */
    public function groups(): array
    {
        do {
            $mergedAny = $this->mergeAlikePairs();
        } while ($mergedAny);
        ksort($this->groups);

        return array_values($this->groups);
    }

    private function mergeAlikePairs(): bool
    {
        $mergedKeys = [];
        foreach ($this->collectCandidatePairs() as [, $lowerKey, $higherKey]) {
            if (isset($mergedKeys[$lowerKey]) || isset($mergedKeys[$higherKey])) {
                continue;
            }
            $this->merge($lowerKey, $higherKey);
            $mergedKeys[$lowerKey] = true;
            $mergedKeys[$higherKey] = true;
        }

        return $mergedKeys !== [];
    }

    /** @return list<array{float, int, int}> similarity, lower and higher group key, most alike first */
    private function collectCandidatePairs(): array
    {
        $similarities = [];
        foreach ($this->indexGroupsByStem() as $groupKeys) {
            foreach ($groupKeys as $position => $lowerKey) {
                foreach (\array_slice($groupKeys, $position + 1) as $higherKey) {
                    $similarities[$lowerKey][$higherKey] ??= $this->computeMergeSimilarity($lowerKey, $higherKey);
                }
            }
        }

        $pairs = [];
        foreach ($similarities as $lowerKey => $similaritiesByHigherKey) {
            foreach ($similaritiesByHigherKey as $higherKey => $similarity) {
                if ($similarity >= self::MERGE_SIMILARITY) {
                    $pairs[] = [$similarity, $lowerKey, $higherKey];
                }
            }
        }
        usort(
            $pairs,
            static fn (array $left, array $right): int => [$right[0], $left[1], $left[2]]
                <=> [$left[0], $right[1], $right[2]],
        );

        return $pairs;
    }

    /** @return array<int, list<int>> stem id => keys of the groups whose profile holds it, ascending */
    private function indexGroupsByStem(): array
    {
        $groupKeysByStem = [];
        foreach ($this->profiles as $groupKey => $profile) {
            foreach (array_keys($profile->stemWeights) as $stemId) {
                $groupKeysByStem[$stemId][] = $groupKey;
            }
        }

        return $groupKeysByStem;
    }

    private function computeMergeSimilarity(int $lowerKey, int $higherKey): float
    {
        $lowerProfile = $this->profiles[$lowerKey];
        $higherProfile = $this->profiles[$higherKey];
        if (!$lowerProfile->isWithinGap($higherProfile, $this->run->window->maxLinkGapSeconds())) {
            return 0.0;
        }

        return $lowerProfile->computeSimilarity($higherProfile);
    }

    private function merge(int $lowerKey, int $higherKey): void
    {
        $members = [...$this->groups[$lowerKey], ...$this->groups[$higherKey]];
        sort($members);
        $this->groups[$lowerKey] = $members;
        $this->profiles[$lowerKey] = GroupProfiles::build($this->run, $members);
        unset($this->groups[$higherKey], $this->profiles[$higherKey]);
    }
}
