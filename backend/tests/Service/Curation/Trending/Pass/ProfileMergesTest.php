<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending\Pass;

use App\Enum\TrendingWindow;
use App\Service\Curation\Trending\Pass\ProfileMerges;
use App\Service\Curation\Trending\Pass\TrendingRun;
use App\Tests\Support\TrendingRuns;
use PHPUnit\Framework\TestCase;

final class ProfileMergesTest extends TestCase
{
    private const string SAME_TIME = '2031-05-07 08:00:00';

    private const array ALIKE_HEADLINES = [
        'Zorbel Quintar Pemla',
        'Zorbel Quintar Fuskit',
        'Zorbel Quintar Drabbit',
        'Zorbel Quintar Wenko',
        'Zorbel Quintar Glimsy',
        'Zorbel Quintar Hobrat',
    ];

    public function testAlikeGroupsMerge(): void
    {
        $run = $this->runOf(self::ALIKE_HEADLINES);

        self::assertSame([[1, 2, 3, 4, 5, 6]], $this->merged($run, [[1, 3, 5], [2, 4, 6]]));
    }

    public function testMergesChainAcrossRounds(): void
    {
        self::assertSame([range(1, 12)], $this->merged($this->chainRun(), self::chainGroups()));
    }

    public function testTheMostAlikePairMergesFirst(): void
    {
        $run = $this->runOf([
            ...array_fill(0, 3, 'Murnel Teskar Vorlip Kalbo'),
            ...array_fill(0, 3, 'Murnel Teskar Vorlip Kalbo Gimbor Hodrin'),
            ...array_fill(0, 3, 'Gimbor Hodrin'),
        ]);

        self::assertSame(
            [[1, 2, 3, 4, 5, 6], [7, 8, 9]],
            $this->merged($run, [[1, 2, 3], [4, 5, 6], [7, 8, 9]]),
        );
    }

    public function testGroupsJustBelowTheMergeSimilarityStayApart(): void
    {
        $run = $this->runWithEvenRarity('Kalbo Paskel Gimbor', 'Hodrin Lumpo Fradel Quobin Wistel');

        self::assertSame([[1, 2, 3], [4, 5, 6]], $this->merged($run, [[1, 2, 3], [4, 5, 6]]));
    }

    public function testGroupsExactlyAtTheMergeSimilarityMerge(): void
    {
        $run = $this->runWithEvenRarity('Kalbo Paskel Gimbor', 'Hodrin Lumpo Fradel Quobin');

        self::assertSame([[1, 2, 3, 4, 5, 6]], $this->merged($run, [[1, 2, 3], [4, 5, 6]]));
    }

    public function testGroupsSharingOneStemAmongManyStayApart(): void
    {
        $run = $this->runOf([
            ...array_fill(0, 3, 'Trumbel Hopsik Lavren Moskut'),
            ...array_fill(0, 3, 'Trumbel Fradel Quobin Wistel'),
        ]);

        self::assertSame([[1, 2, 3], [4, 5, 6]], $this->merged($run, [[1, 2, 3], [4, 5, 6]]));
    }

    public function testAlikeGroupsBeyondTheLinkGapStayApart(): void
    {
        $run = TrendingRuns::of(TrendingWindow::Week);
        foreach (self::ALIKE_HEADLINES as $index => $headline) {
            $publishedAt = $index < 3 ? '2031-05-01 08:00:00' : '2031-05-04 08:00:00';
            $run->add(TrendingRuns::entry($index + 1, $index + 1, $headline, $publishedAt), [], null);
        }

        self::assertSame([[1, 2, 3], [4, 5, 6]], $this->merged($run, [[1, 2, 3], [4, 5, 6]]));
    }

    public function testATwoMemberGroupIsNeverMerged(): void
    {
        $run = $this->runOf(array_fill(0, 5, 'Zorbel Quintar'));

        self::assertSame([[1, 2], [3, 4, 5]], $this->merged($run, [[1, 2], [3, 4, 5]]));
    }

    public function testTheOrderGroupsArriveInDoesNotChangeTheMerges(): void
    {
        $groups = array_reverse(self::chainGroups());

        self::assertSame([range(1, 12)], $this->merged($this->chainRun(), $groups));
    }

    public function testOutputKeepsMembersAscendingAndGroupsByLowestMember(): void
    {
        $run = $this->runOf([...self::ALIKE_HEADLINES, ...array_fill(0, 3, 'Ulmira Sevpot Kandor')]);

        self::assertSame(
            [[1, 2, 3, 4, 5, 6], [7, 8, 9]],
            $this->merged($run, [[4, 5, 6], [1, 2, 3], [7, 8, 9]]),
        );
    }

    public function testUnorderedGroupsAndMembersComeOutSorted(): void
    {
        $run = $this->runOf([
            ...array_fill(0, 3, 'Zorbel Quintar'),
            ...array_fill(0, 3, 'Ulmira Sevpot Kandor'),
            ...array_fill(0, 3, 'Zorbel Quintar'),
        ]);

        self::assertSame(
            [[1, 2, 3, 7, 8, 9], [4, 5, 6]],
            $this->merged($run, [[6, 4, 5], [7, 8, 9], [3, 1, 2]]),
        );
    }

    /** The middle group outnumbers the others, so its union with the first drops the first's own stems. */
    private function chainRun(): TrendingRun
    {
        return $this->runOf([
            ...array_fill(0, 3, 'Kalbo Murnel Teskar Vorlip Paskel Gimbor'),
            ...array_fill(0, 6, 'Teskar Vorlip Paskel Gimbor Hodrin Lumpo'),
            ...array_fill(0, 3, 'Paskel Gimbor Hodrin Lumpo Fradel Quobin'),
        ]);
    }

    /** @return list<non-empty-list<int>> */
    private static function chainGroups(): array
    {
        return [[1, 2, 3], range(4, 9), [10, 11, 12]];
    }

    /**
     * @param list<non-empty-list<int>> $groups
     *
     * @return list<non-empty-list<int>>
     */
    private function merged(TrendingRun $run, array $groups): array
    {
        return new ProfileMerges($run, $groups)->groups();
    }

    /** Three ungrouped entries carry both groups' own words, so every stem weighs the same and only counts matter. */
    private function runWithEvenRarity(string $oneGroupWords, string $otherGroupWords): TrendingRun
    {
        return $this->runOf([
            ...array_fill(0, 3, 'Murnel Teskar Vorlip ' . $oneGroupWords),
            ...array_fill(0, 3, 'Murnel Teskar Vorlip ' . $otherGroupWords),
            ...array_fill(0, 3, $oneGroupWords . ' ' . $otherGroupWords),
        ]);
    }

    /** @param list<string> $headlines */
    private function runOf(array $headlines): TrendingRun
    {
        $run = TrendingRuns::of(TrendingWindow::Day);
        foreach ($headlines as $index => $headline) {
            $run->add(TrendingRuns::entry($index + 1, $index + 1, $headline, self::SAME_TIME), [], null);
        }

        return $run;
    }
}
