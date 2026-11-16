<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending;

use App\Enum\TrendingWindow;
use App\Service\Curation\Trending\Model\StoredStoryModel;
use App\Service\Curation\Trending\Model\StoryAssignmentModel;
use App\Service\Curation\Trending\Model\StoryMatchModel;
use App\Service\Curation\Trending\Model\StoryMemberModel;
use App\Service\Curation\Trending\Model\StoryMergeModel;
use App\Service\Curation\Trending\Model\TrendingStoryModel;

/**
 * @phpstan-type Candidate array{
 *     group: int,
 *     story: int,
 *     storyId: int,
 *     storySize: int,
 *     shared: int,
 *     overlap: float,
 *     lowestEntryId: int,
 * }
 */
final readonly class StoryMatching
{
    /** A group sharing less than half of the smaller side is a different event, not the same story grown. */
    public const float MATCH_THRESHOLD = 0.5;

    /**
     * @param list<TrendingStoryModel> $groups
     * @param list<StoredStoryModel>   $stored
     */
    public function match(array $groups, array $stored, TrendingWindow $window): StoryMatchModel
    {
        $candidates = $this->rankedCandidates($groups, $stored);
        $storyOfGroup = $this->oneToOne($candidates);
        $merges = $window->mergesStories() ? $this->merges($candidates, $storyOfGroup) : [];

        return new StoryMatchModel(
            $this->assignments($groups, $stored, $storyOfGroup, $merges),
            array_map(
                static fn (array $merge): StoryMergeModel => new StoryMergeModel(
                    $stored[$merge['survivor']]->id,
                    $stored[$merge['merged']]->id,
                ),
                $merges,
            ),
            $this->splitCount($groups, $candidates, $storyOfGroup),
        );
    }

    /**
     * @param list<TrendingStoryModel> $groups
     * @param list<StoredStoryModel>   $stored
     *
     * @return list<Candidate>
     */
    private function rankedCandidates(array $groups, array $stored): array
    {
        $candidates = [];
        foreach ($groups as $groupIndex => $group) {
            $entryIds = self::entryIdsOf($group);
            foreach ($stored as $storyIndex => $story) {
                $candidate = $this->candidate($groupIndex, $entryIds, $storyIndex, $story);
                if ($candidate !== null) {
                    $candidates[] = $candidate;
                }
            }
        }
        usort(
            $candidates,
            static fn (array $left, array $right): int => self::rankKey($right) <=> self::rankKey($left),
        );

        return $candidates;
    }

    /**
     * @param non-empty-list<int> $groupEntryIds
     *
     * @return Candidate|null
     */
    private function candidate(
        int $groupIndex,
        array $groupEntryIds,
        int $storyIndex,
        StoredStoryModel $story,
    ): ?array {
        $shared = \count(array_intersect($groupEntryIds, $story->entryIds));
        $smallerSide = min(\count($groupEntryIds), \count($story->entryIds));
        if ($shared === 0) {
            return null;
        }
        $overlap = $shared / $smallerSide;
        if ($overlap < self::MATCH_THRESHOLD) {
            return null;
        }

        return [
            'group' => $groupIndex,
            'story' => $storyIndex,
            'storyId' => $story->id,
            'storySize' => \count($story->entryIds),
            'shared' => $shared,
            'overlap' => $overlap,
            'lowestEntryId' => min($groupEntryIds),
        ];
    }

    /**
     * @param list<Candidate> $candidates
     *
     * @return array<int, int> story index by group index
     */
    private function oneToOne(array $candidates): array
    {
        $storyOfGroup = [];
        $takenStories = [];
        foreach ($candidates as $candidate) {
            if (isset($storyOfGroup[$candidate['group']]) || isset($takenStories[$candidate['story']])) {
                continue;
            }
            $storyOfGroup[$candidate['group']] = $candidate['story'];
            $takenStories[$candidate['story']] = true;
        }

        return $storyOfGroup;
    }

    /**
     * @param list<Candidate>   $candidates
     * @param array<int, int>   $storyOfGroup
     *
     * @return list<array{survivor: int, merged: int}>
     */
    private function merges(array $candidates, array $storyOfGroup): array
    {
        $taken = array_flip($storyOfGroup);
        $merges = [];
        foreach ($candidates as $candidate) {
            if (isset($taken[$candidate['story']])) {
                continue;
            }
            $taken[$candidate['story']] = true;
            $merges[] = ['survivor' => $storyOfGroup[$candidate['group']], 'merged' => $candidate['story']];
        }

        return $merges;
    }

    /**
     * @param list<TrendingStoryModel>                      $groups
     * @param list<StoredStoryModel>                        $stored
     * @param array<int, int>                               $storyOfGroup
     * @param list<array{survivor: int, merged: int}>       $merges
     *
     * @return list<StoryAssignmentModel>
     */
    private function assignments(array $groups, array $stored, array $storyOfGroup, array $merges): array
    {
        $assignments = [];
        foreach ($groups as $groupIndex => $group) {
            $storyIndex = $storyOfGroup[$groupIndex] ?? null;
            $assignments[] = new StoryAssignmentModel(
                $group,
                $storyIndex === null ? null : $stored[$storyIndex]->id,
                $storyIndex === null ? [] : $this->storedEntryIds($stored, $storyIndex, $merges),
            );
        }

        return $assignments;
    }

    /**
     * @param list<StoredStoryModel>                  $stored
     * @param list<array{survivor: int, merged: int}> $merges
     *
     * @return list<int>
     */
    private function storedEntryIds(array $stored, int $storyIndex, array $merges): array
    {
        $entryIds = $stored[$storyIndex]->entryIds;
        foreach ($merges as $merge) {
            if ($merge['survivor'] === $storyIndex) {
                $entryIds = [...$entryIds, ...$stored[$merge['merged']]->entryIds];
            }
        }

        return $entryIds;
    }

    /**
     * @param list<TrendingStoryModel> $groups
     * @param list<Candidate>          $candidates
     * @param array<int, int>          $storyOfGroup
     */
    private function splitCount(array $groups, array $candidates, array $storyOfGroup): int
    {
        $groupsWithCandidate = array_flip(array_column($candidates, 'group'));

        return \count(array_diff_key($groupsWithCandidate, $storyOfGroup));
    }

    /**
     * @param Candidate $candidate
     *
     * @return array{float, int, int, int, int} larger sorts first: overlap, shared, story size, older id, older group
     */
    private static function rankKey(array $candidate): array
    {
        return [
            $candidate['overlap'],
            $candidate['shared'],
            $candidate['storySize'],
            -$candidate['storyId'],
            -$candidate['lowestEntryId'],
        ];
    }

    /** @return non-empty-list<int> */
    private static function entryIdsOf(TrendingStoryModel $group): array
    {
        return array_map(static fn (StoryMemberModel $member): int => $member->entryId, $group->members);
    }
}
