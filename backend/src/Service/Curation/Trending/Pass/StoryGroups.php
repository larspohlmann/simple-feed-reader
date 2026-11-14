<?php

declare(strict_types=1);

namespace App\Service\Trending\Pass;

use App\Service\Trending\Model\StoryLinkModel;

/**
 * Groups built link by link, strongest first: an entry joins only when it links to half of the group's newest
 * members, and two groups merge only when as many of their pairs link, so one weak bridge glues nothing (#2017).
 */
final class StoryGroups
{
    private const int SAMPLE_SIZE = 8;

    /** @var array<int, array<int, true>> */
    private array $linked = [];

    /** @var array<int, int> entry id => group id */
    private array $groupOf = [];

    /** @var array<int, non-empty-list<int>> group id => member entry ids */
    private array $members = [];

    private int $nextGroupId = 0;

    /** @param list<StoryLinkModel> $links every link of the run: the weaker ones count toward breadth */
    public function __construct(private readonly TrendingRun $run, array $links)
    {
        foreach ($links as $link) {
            $this->linked[$link->firstEntryId][$link->secondEntryId] = true;
            $this->linked[$link->secondEntryId][$link->firstEntryId] = true;
        }
    }

    public function consider(StoryLinkModel $link): void
    {
        $firstGroupId = $this->groupOf[$link->firstEntryId] ?? null;
        $secondGroupId = $this->groupOf[$link->secondEntryId] ?? null;
        if ($firstGroupId !== null && $secondGroupId !== null) {
            $this->mergeIfBroad($firstGroupId, $secondGroupId);

            return;
        }
        if ($firstGroupId !== null) {
            $this->joinIfBroad($link->secondEntryId, $firstGroupId);

            return;
        }
        if ($secondGroupId !== null) {
            $this->joinIfBroad($link->firstEntryId, $secondGroupId);

            return;
        }
        $this->open($link->firstEntryId, $link->secondEntryId);
    }

    /** @return list<non-empty-list<int>> members ascending, groups by their lowest member */
    public function groups(): array
    {
        $groups = [];
        foreach ($this->members as $members) {
            sort($members);
            $groups[] = $members;
        }
        usort($groups, static fn (array $left, array $right): int => $left[0] <=> $right[0]);

        return $groups;
    }

    private function open(int $oneEntryId, int $otherEntryId): void
    {
        $groupId = $this->nextGroupId++;
        $this->members[$groupId] = [$oneEntryId, $otherEntryId];
        $this->groupOf[$oneEntryId] = $groupId;
        $this->groupOf[$otherEntryId] = $groupId;
    }

    private function joinIfBroad(int $entryId, int $groupId): void
    {
        $sample = $this->sampleOf($groupId);
        if ($this->linkCount([$entryId], $sample) < self::half(\count($sample))) {
            return;
        }
        $this->members[$groupId][] = $entryId;
        $this->groupOf[$entryId] = $groupId;
    }

    private function mergeIfBroad(int $firstGroupId, int $secondGroupId): void
    {
        if ($firstGroupId === $secondGroupId) {
            return;
        }
        $firstSample = $this->sampleOf($firstGroupId);
        $secondSample = $this->sampleOf($secondGroupId);
        $possiblePairs = \count($firstSample) * \count($secondSample);
        if ($this->linkCount($firstSample, $secondSample) < self::half($possiblePairs)) {
            return;
        }
        foreach ($this->members[$secondGroupId] as $entryId) {
            $this->members[$firstGroupId][] = $entryId;
            $this->groupOf[$entryId] = $firstGroupId;
        }
        unset($this->members[$secondGroupId]);
    }

    /** @return list<int> */
    private function sampleOf(int $groupId): array
    {
        $members = $this->members[$groupId];
        usort(
            $members,
            fn (int $left, int $right): int => [$this->run->effectiveTimeOf($right), $right]
                <=> [$this->run->effectiveTimeOf($left), $left],
        );

        return \array_slice($members, 0, self::SAMPLE_SIZE);
    }

    /**
     * @param list<int> $entryIds
     * @param list<int> $otherEntryIds
     */
    private function linkCount(array $entryIds, array $otherEntryIds): int
    {
        $others = array_flip($otherEntryIds);
        $count = 0;
        foreach ($entryIds as $entryId) {
            $count += \count(array_intersect_key($this->linked[$entryId] ?? [], $others));
        }

        return $count;
    }

    private static function half(int $count): int
    {
        return (int) ceil($count / 2);
    }
}
