<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Pass;

use App\Service\Curation\Trending\Model\StoryLinkModel;

/**
 * Groups built link by link, strongest first: an entry joins only when it links to half of the group's newest
 * members, and two groups merge only when as many of their pairs link, so one weak bridge glues nothing (#2017).
 */
final class StoryGroups
{
    private const int SAMPLE_SIZE = 8;

    /** @var array<int, int> entry id => group id */
    private array $groupOf = [];

    /** @var array<int, non-empty-list<int>> group id => member entry ids */
    private array $members = [];

    private int $nextGroupId = 0;

    public function __construct(private readonly TrendingRun $run, private readonly LinkedPairs $linkedPairs)
    {
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
        $sample = $this->pickSample($groupId);
        if ($this->linkedPairs->countBetween([$entryId], $sample) < self::half(\count($sample))) {
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
        $firstSample = $this->pickSample($firstGroupId);
        $secondSample = $this->pickSample($secondGroupId);
        $possiblePairs = \count($firstSample) * \count($secondSample);
        if ($this->linkedPairs->countBetween($firstSample, $secondSample) < self::half($possiblePairs)) {
            return;
        }
        foreach ($this->members[$secondGroupId] as $entryId) {
            $this->members[$firstGroupId][] = $entryId;
            $this->groupOf[$entryId] = $firstGroupId;
        }
        unset($this->members[$secondGroupId]);
    }

    /** @return list<int> */
    private function pickSample(int $groupId): array
    {
        $members = $this->members[$groupId];
        usort(
            $members,
            fn (int $left, int $right): int => [$this->run->readEffectiveTime($right), $right]
                <=> [$this->run->readEffectiveTime($left), $left],
        );

        return \array_slice($members, 0, self::SAMPLE_SIZE);
    }

    private static function half(int $count): int
    {
        return (int) ceil($count / 2);
    }
}
