<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending;

use App\Enum\TrendingWindow;
use App\Service\Curation\Trending\Model\StoryLinkModel;
use App\Service\Curation\Trending\Pass\TrendingRun;
use App\Service\Curation\Trending\StoryGrouping;
use App\Tests\Support\TrendingRuns;
use PHPUnit\Framework\TestCase;

final class StoryGroupingTest extends TestCase
{
    public function testTwoUngroupedEntriesOpenAGroup(): void
    {
        self::assertSame([[1, 2]], self::grouped(2, [new StoryLinkModel(1, 2, 20.0)]));
    }

    public function testOneLinkJoinsATwoMemberGroup(): void
    {
        self::assertSame(
            [[1, 2, 3]],
            self::grouped(3, [new StoryLinkModel(1, 2, 30.0), new StoryLinkModel(1, 3, 20.0)]),
        );
    }

    public function testAnEntryLinkedToHalfOfTheGroupJoins(): void
    {
        $links = [
            ...self::triangle(1, 2, 3, 30.0),
            new StoryLinkModel(1, 4, 20.0),
            new StoryLinkModel(2, 4, 19.0),
        ];

        self::assertSame([[1, 2, 3, 4]], self::grouped(4, $links));
    }

    public function testAnEntryLinkedToLessThanHalfOfTheGroupStaysOut(): void
    {
        $links = [...self::triangle(1, 2, 3, 30.0), new StoryLinkModel(3, 4, 20.0)];

        self::assertSame([[1, 2, 3]], self::grouped(4, $links));
    }

    public function testAWeakBridgeDoesNotMergeTwoGroups(): void
    {
        $links = [
            ...self::triangle(1, 2, 3, 30.0),
            ...self::triangle(4, 5, 6, 25.0),
            new StoryLinkModel(3, 4, 10.0),
        ];

        self::assertSame([[1, 2, 3], [4, 5, 6]], self::grouped(6, $links));
    }

    public function testGroupsLinkedAcrossHalfTheirPairsMerge(): void
    {
        $links = [
            new StoryLinkModel(1, 2, 30.0),
            new StoryLinkModel(3, 4, 29.0),
            new StoryLinkModel(1, 3, 20.0),
            new StoryLinkModel(2, 4, 19.0),
        ];

        self::assertSame([[1, 2, 3, 4]], self::grouped(4, $links));
    }

    public function testGroupsLinkedAcrossFewerThanHalfTheirPairsStayApart(): void
    {
        $links = [new StoryLinkModel(1, 2, 30.0), new StoryLinkModel(3, 4, 29.0), new StoryLinkModel(1, 3, 20.0)];

        self::assertSame([[1, 2], [3, 4]], self::grouped(4, $links));
    }

    public function testOnlyTheEightNewestMembersOfABigGroupCount(): void
    {
        $links = self::completeGraph(10, 100.0);
        foreach ([7, 8, 9, 10] as $newMember) {
            $links[] = new StoryLinkModel($newMember, 11, 5.0);
        }
        foreach ([1, 2, 3, 4] as $oldMember) {
            $links[] = new StoryLinkModel($oldMember, 12, 4.0);
        }

        self::assertSame([[1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11]], self::grouped(12, $links));
    }

    public function testNewestMeansPublishedLastNotHighestId(): void
    {
        $links = self::completeGraph(10, 100.0);
        foreach ([1, 2, 3, 4] as $newMember) {
            $links[] = new StoryLinkModel($newMember, 11, 5.0);
        }
        foreach ([7, 8, 9, 10] as $oldMember) {
            $links[] = new StoryLinkModel($oldMember, 12, 4.0);
        }

        $groups = (new StoryGrouping())->group(self::runOfNewestLowestId(12), $links);

        self::assertSame([[1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11]], $groups);
    }

    public function testTiesAreBrokenByEntryId(): void
    {
        $links = [new StoryLinkModel(3, 4, 10.0), new StoryLinkModel(2, 3, 10.0), new StoryLinkModel(1, 2, 10.0)];

        self::assertSame([[1, 2, 3]], self::grouped(4, $links));
    }

    public function testTheOrderLinksArriveInDoesNotChangeTheGroups(): void
    {
        $links = [
            ...self::triangle(1, 2, 3, 30.0),
            ...self::triangle(4, 5, 6, 30.0),
            new StoryLinkModel(3, 4, 10.0),
            new StoryLinkModel(1, 7, 12.0),
            new StoryLinkModel(2, 7, 12.0),
            new StoryLinkModel(6, 8, 12.0),
        ];
        $expected = [[1, 2, 3, 7], [4, 5, 6]];

        self::assertSame($expected, self::grouped(8, $links));
        self::assertSame($expected, self::grouped(8, array_reverse($links)));
        self::assertSame(
            $expected,
            self::grouped(8, array_merge(self::everyOther($links, 1), self::everyOther($links, 0))),
        );
    }

    /**
     * @param list<StoryLinkModel> $links
     *
     * @return list<list<int>>
     */
    private static function grouped(int $entryCount, array $links): array
    {
        return (new StoryGrouping())->group(self::runOf($entryCount), $links);
    }

    private static function runOf(int $entryCount): TrendingRun
    {
        return self::runPublishedAt($entryCount, static fn (int $entryId): int => $entryId);
    }

    private static function runOfNewestLowestId(int $entryCount): TrendingRun
    {
        return self::runPublishedAt($entryCount, static fn (int $entryId): int => $entryCount + 1 - $entryId);
    }

    /** @param callable(int): int $hourOf */
    private static function runPublishedAt(int $entryCount, callable $hourOf): TrendingRun
    {
        $run = TrendingRuns::of(TrendingWindow::Day);
        for ($entryId = 1; $entryId <= $entryCount; $entryId++) {
            $publishedAt = \sprintf('2031-05-14 %02d:00:00', $hourOf($entryId));
            $run->add(TrendingRuns::entry($entryId, $entryId, 'Entry', $publishedAt), []);
        }

        return $run;
    }

    /** @return list<StoryLinkModel> */
    private static function triangle(int $first, int $second, int $third, float $strength): array
    {
        return [
            new StoryLinkModel($first, $second, $strength),
            new StoryLinkModel($first, $third, $strength - 0.5),
            new StoryLinkModel($second, $third, $strength - 1.0),
        ];
    }

    /** @return list<StoryLinkModel> */
    private static function completeGraph(int $size, float $strongest): array
    {
        $links = [];
        $strength = $strongest;
        for ($first = 1; $first <= $size; $first++) {
            for ($second = $first + 1; $second <= $size; $second++) {
                $links[] = new StoryLinkModel($first, $second, $strength);
                $strength -= 0.1;
            }
        }

        return $links;
    }

    /**
     * @param list<StoryLinkModel> $links
     *
     * @return list<StoryLinkModel>
     */
    private static function everyOther(array $links, int $offset): array
    {
        return array_values(array_filter(
            $links,
            static fn (int $index): bool => $index % 2 === $offset,
            \ARRAY_FILTER_USE_KEY,
        ));
    }
}
