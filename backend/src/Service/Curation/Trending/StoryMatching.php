<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending;

use App\Enum\TrendingWindow;
use App\Service\Curation\Trending\Model\StoredStoryModel;
use App\Service\Curation\Trending\Model\StoryAssignmentModel;
use App\Service\Curation\Trending\Model\StoryMatchModel;
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
    private const float MATCH_THRESHOLD = 0.5;

    /**
     * @param list<TrendingStoryModel> $groups
     * @param list<StoredStoryModel>   $stored
     */
    public function match(array $groups, array $stored, TrendingWindow $window): StoryMatchModel
    {
        $storiesOfEntry = $this->storiesByEntry($stored);
        $candidates = $this->rankedCandidates($groups, $stored, $storiesOfEntry);
        if (!$window->mergesStories()) {
            return $this->attachedToTheirBest($groups, $stored, $candidates);
        }
        $storyOfGroup = $this->oneToOne($candidates);
        $merges = $this->merges($candidates, $storyOfGroup);

        return new StoryMatchModel(
            $this->claiming(
                $this->assignments($groups, $stored, $storyOfGroup, $merges),
                $storiesOfEntry,
                $this->survivorIds($stored, $merges),
            ),
            array_map(
                static fn (array $merge): StoryMergeModel => new StoryMergeModel(
                    $stored[$merge['survivor']]->id,
                    $stored[$merge['merged']]->id,
                ),
                $merges,
            ),
            $this->splitCount($candidates, $storyOfGroup),
        );
    }

    /**
     * @param list<TrendingStoryModel> $groups
     * @param list<StoredStoryModel>   $stored
     * @param array<int, list<int>>    $storiesOfEntry
     *
     * @return list<Candidate>
     */
    private function rankedCandidates(array $groups, array $stored, array $storiesOfEntry): array
    {
        $candidates = [];
        foreach ($groups as $groupIndex => $group) {
            $entryIds = $group->entryIds();
            foreach ($this->sharedByStory($entryIds, $storiesOfEntry) as $storyIndex => $shared) {
                $candidate = $this->candidate($entryIds, $stored[$storyIndex], $shared);
                if ($candidate !== null) {
                    $candidates[] = ['group' => $groupIndex, 'story' => $storyIndex] + $candidate;
                }
            }
        }

        return $this->ranked($candidates);
    }

    /**
     * @param list<StoredStoryModel> $stored
     *
     * @return array<int, list<int>> story indexes by entry id
     */
    private function storiesByEntry(array $stored): array
    {
        $storiesOfEntry = [];
        foreach ($stored as $storyIndex => $story) {
            foreach ($story->entryIds as $entryId) {
                $storiesOfEntry[$entryId][] = $storyIndex;
            }
        }

        return $storiesOfEntry;
    }

    /**
     * @param list<int>             $entryIds
     * @param array<int, list<int>> $storiesOfEntry
     *
     * @return array<int, positive-int> entries shared by story index
     */
    private function sharedByStory(array $entryIds, array $storiesOfEntry): array
    {
        $sharedByStory = [];
        foreach ($entryIds as $entryId) {
            foreach ($storiesOfEntry[$entryId] ?? [] as $storyIndex) {
                $sharedByStory[$storyIndex] = ($sharedByStory[$storyIndex] ?? 0) + 1;
            }
        }

        return $sharedByStory;
    }

    /**
     * @param non-empty-list<int> $groupEntryIds
     *
     * @return array{storyId: int, storySize: int, shared: int, overlap: float, lowestEntryId: int}|null
     */
    private function candidate(array $groupEntryIds, StoredStoryModel $story, int $shared): ?array
    {
        $overlap = $shared / min(\count($groupEntryIds), \count($story->entryIds));
        if ($overlap < self::MATCH_THRESHOLD) {
            return null;
        }

        return [
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
     * @return list<Candidate>
     */
    private function ranked(array $candidates): array
    {
        $keyed = array_map(static fn (array $candidate): array => [self::rankKey($candidate), $candidate], $candidates);
        usort($keyed, static fn (array $left, array $right): int => $right[0] <=> $left[0]);

        return array_column($keyed, 1);
    }

    /**
     * A day group joins the story it overlaps best, even one another day group joined: only the week shapes identity.
     *
     * @param list<TrendingStoryModel> $groups
     * @param list<StoredStoryModel>   $stored
     * @param list<Candidate>          $candidates
     */
    private function attachedToTheirBest(array $groups, array $stored, array $candidates): StoryMatchModel
    {
        $storyOfGroup = [];
        foreach ($candidates as $candidate) {
            $storyOfGroup[$candidate['group']] ??= $candidate['story'];
        }

        return new StoryMatchModel($this->assignments($groups, $stored, $storyOfGroup, []), [], 0);
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
     * @param list<StoryAssignmentModel> $assignments
     * @param array<int, list<int>>      $storiesOfEntry
     * @param array<int, int>            $survivorIds
     *
     * @return list<StoryAssignmentModel>
     */
    private function claiming(array $assignments, array $storiesOfEntry, array $survivorIds): array
    {
        return array_map(
            fn (StoryAssignmentModel $assignment): StoryAssignmentModel => $assignment->claiming(
                $this->heldElsewhere($assignment, $storiesOfEntry, $survivorIds),
            ),
            $assignments,
        );
    }

    /**
     * @param array<int, list<int>> $storiesOfEntry
     * @param array<int, int>       $survivorIds
     *
     * @return list<int>
     */
    private function heldElsewhere(StoryAssignmentModel $assignment, array $storiesOfEntry, array $survivorIds): array
    {
        return array_values(array_filter(
            $assignment->group->entryIds(),
            static fn (int $entryId): bool => array_any(
                $storiesOfEntry[$entryId] ?? [],
                static fn (int $storyIndex): bool => $survivorIds[$storyIndex] !== $assignment->storyId,
            ),
        ));
    }

    /**
     * @param list<StoredStoryModel>                  $stored
     * @param list<array{survivor: int, merged: int}> $merges
     *
     * @return array<int, int> by story index, the id of the story that holds its members once the merges are done
     */
    private function survivorIds(array $stored, array $merges): array
    {
        $survivorIds = array_map(static fn (StoredStoryModel $story): int => $story->id, $stored);
        foreach ($merges as $merge) {
            $survivorIds[$merge['merged']] = $stored[$merge['survivor']]->id;
        }

        return $survivorIds;
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
     * @param list<Candidate> $candidates
     * @param array<int, int> $storyOfGroup
     */
    private function splitCount(array $candidates, array $storyOfGroup): int
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
}
