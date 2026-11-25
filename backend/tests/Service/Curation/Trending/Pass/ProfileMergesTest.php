<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending\Pass;

use App\Enum\TrendingWindow;
use App\Service\Curation\Trending\Model\StoryLinkModel;
use App\Service\Curation\Trending\Pass\LinkedPairs;
use App\Service\Curation\Trending\Pass\ProfileMerges;
use App\Service\Curation\Trending\Pass\TrendingRun;
use App\Tests\Support\TrendingRuns;
use PHPUnit\Framework\TestCase;

final class ProfileMergesTest extends TestCase
{
    private const string SAME_TIME = '2031-05-07 08:00:00';

    private const array WORD_POOL = [
        'Kalbo', 'Paskel', 'Gimbor', 'Hodrin', 'Lumpo', 'Fradel', 'Quobin', 'Wistel', 'Trumbel', 'Hopsik',
        'Lavren', 'Moskut', 'Zorbel', 'Quintar', 'Pemla', 'Fuskit', 'Drabbit', 'Wenko', 'Glimsy', 'Hobrat',
        'Ulmira', 'Sevpot', 'Kandor', 'Brantu', 'Cresmo', 'Dolvik', 'Ephrim', 'Fandro', 'Garnet', 'Hollis',
    ];

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

    public function testGroupsInTheBandMergeWhenTheirMembersLinkDensely(): void
    {
        $run = $this->runWithEvenRarity('Kalbo Paskel Gimbor', 'Hodrin Lumpo Fradel Quobin Wistel');

        self::assertSame([[1, 2, 3, 4, 5, 6]], $this->merged($run, [[1, 2, 3], [4, 5, 6]], [[1, 4]]));
    }

    public function testLinksInsideOneGroupDoNotCountTowardTheDensity(): void
    {
        $run = $this->runWithEvenRarity('Kalbo Paskel Gimbor', 'Hodrin Lumpo Fradel Quobin Wistel');

        self::assertSame(
            [[1, 2, 3], [4, 5, 6]],
            $this->merged($run, [[1, 2, 3], [4, 5, 6]], [[1, 2], [4, 5]]),
        );
    }

    public function testGroupsExactlyAtTheLinkedMergeSimilarityMerge(): void
    {
        $run = $this->runWithEvenRarity('Kalbo Paskel Gimbor', 'Hodrin Lumpo Fradel Quobin Wistel Trumbel');

        self::assertSame([[1, 2, 3, 4, 5, 6]], $this->merged($run, [[1, 2, 3], [4, 5, 6]], [[1, 4]]));
    }

    public function testGroupsBelowTheLinkedMergeSimilarityStayApartHoweverDense(): void
    {
        $run = $this->runWithEvenRarity(
            'Kalbo Paskel Gimbor',
            'Hodrin Lumpo Fradel Quobin Wistel Trumbel Hopsik',
        );
        $everyCrossLink = [[1, 4], [1, 5], [1, 6], [2, 4], [2, 5], [2, 6], [3, 4], [3, 5], [3, 6]];

        self::assertSame([[1, 2, 3], [4, 5, 6]], $this->merged($run, [[1, 2, 3], [4, 5, 6]], $everyCrossLink));
    }

    public function testGroupsJustBelowTheLinkedMergeSimilarityStayApartHoweverDense(): void
    {
        $run = $this->runWithWordCounts(6, 3, 16, 3, 3);

        self::assertSame([[1, 2, 3], [4, 5, 6]], $this->merged($run, [[1, 2, 3], [4, 5, 6]], self::crossLinks(3, 3)));
    }

    public function testADensityJustBelowATenthStaysApart(): void
    {
        $run = $this->runWithWordCounts(3, 3, 5, 3, 7);

        self::assertSame(
            [[1, 2, 3], range(4, 10)],
            $this->merged($run, [[1, 2, 3], range(4, 10)], [[1, 4], [2, 5]]),
        );
    }

    public function testADensityExactlyATenthMerges(): void
    {
        $run = $this->runWithWordCounts(3, 3, 5, 4, 5);

        self::assertSame(
            [range(1, 9)],
            $this->merged($run, [range(1, 4), range(5, 9)], [[1, 5], [2, 6]]),
        );
    }

    public function testAShortBandPairStaysApartWhileAStrongerPairMergesInTheSameRound(): void
    {
        self::assertSame(
            [[1, 2, 3, 10, 11, 12], [4, 5, 6]],
            $this->merged($this->bandPairWithATwinRun(), self::bandPairWithATwinGroups()),
        );
    }

    public function testALaterRoundJudgesTheDensityOfTheMergedGroups(): void
    {
        self::assertSame(
            [[1, 2, 3, 10, 11, 12], [4, 5, 6]],
            $this->merged($this->bandPairWithATwinRun(), self::bandPairWithATwinGroups(), [[1, 4]]),
        );
    }

    public function testGroupsAboveTheMergeSimilarityMergeWithoutAnyLink(): void
    {
        $run = $this->runWithEvenRarity('Kalbo Paskel Gimbor', 'Hodrin Lumpo Fradel');

        self::assertSame([[1, 2, 3, 4, 5, 6]], $this->merged($run, [[1, 2, 3], [4, 5, 6]]));
    }

    public function testTheDensityMustReachATenth(): void
    {
        $run = $this->runWithEvenRarity('Kalbo Paskel Gimbor', 'Hodrin Lumpo Fradel Quobin Wistel', 4);
        $groups = [[1, 2, 3, 4], [5, 6, 7, 8]];

        self::assertSame($groups, $this->merged($run, $groups, [[1, 5]]));
        self::assertSame([range(1, 8)], $this->merged($run, $groups, [[1, 5], [2, 6]]));
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
     * @param list<array{int, int}> $links
     *
     * @return list<non-empty-list<int>>
     */
    private function merged(TrendingRun $run, array $groups, array $links = []): array
    {
        $linkedPairs = LinkedPairs::fromLinks(array_map(
            static fn (array $link): StoryLinkModel => new StoryLinkModel($link[0], $link[1], 1.0),
            $links,
        ));

        return new ProfileMerges($run, $groups, $linkedPairs)->groups();
    }

    /**
     * Groups 1 and 3 share every headline word and merge first; group 2 sits at 0.264 from each of them,
     * and from their union.
     */
    private function bandPairWithATwinRun(): TrendingRun
    {
        $oneGroupWords = 'Kalbo Paskel Gimbor';
        $otherGroupWords = 'Hodrin Lumpo Fradel Quobin Wistel';

        return $this->runOf([
            ...array_fill(0, 3, 'Murnel Teskar Vorlip ' . $oneGroupWords),
            ...array_fill(0, 3, 'Murnel Teskar Vorlip ' . $otherGroupWords),
            ...array_fill(0, 3, $oneGroupWords . ' ' . $otherGroupWords),
            ...array_fill(0, 3, 'Murnel Teskar Vorlip ' . $oneGroupWords),
        ]);
    }

    /** @return list<non-empty-list<int>> */
    private static function bandPairWithATwinGroups(): array
    {
        return [[1, 2, 3], [4, 5, 6], [10, 11, 12]];
    }

    /** @return list<array{int, int}> */
    private static function crossLinks(int $oneMembers, int $otherMembers): array
    {
        $links = [];
        foreach (range(1, $oneMembers) as $one) {
            foreach (range($oneMembers + 1, $oneMembers + $otherMembers) as $other) {
                $links[] = [$one, $other];
            }
        }

        return $links;
    }

    /**
     * Entries 1.. belong to the first group, the next ones to the second, and ungrouped entries pad every stem
     * to the same frequency, so the similarity is shared / (shared + oneOwn + otherOwn).
     */
    private function runWithWordCounts(
        int $sharedWords,
        int $oneOwnWords,
        int $otherOwnWords,
        int $oneMembers,
        int $otherMembers,
    ): TrendingRun {
        $words = self::WORD_POOL;
        $shared = implode(' ', array_splice($words, 0, $sharedWords));
        $oneOwn = implode(' ', array_splice($words, 0, $oneOwnWords));
        $otherOwn = implode(' ', array_splice($words, 0, $otherOwnWords));
        $headlines = [
            ...array_fill(0, $oneMembers, $shared . ' ' . $oneOwn),
            ...array_fill(0, $otherMembers, $shared . ' ' . $otherOwn),
        ];
        foreach (range(0, max($oneMembers, $otherMembers) - 1) as $index) {
            $oneOwnHere = $index < $otherMembers ? $oneOwn : '';
            $otherOwnHere = $index < $oneMembers ? $otherOwn : '';
            $headlines[] = trim($oneOwnHere . ' ' . $otherOwnHere);
        }

        return $this->runOf($headlines);
    }

    /** Three ungrouped entries carry both groups' own words, so every stem weighs the same and only counts matter. */
    private function runWithEvenRarity(
        string $oneGroupWords,
        string $otherGroupWords,
        int $membersPerGroup = 3,
    ): TrendingRun {
        return $this->runOf([
            ...array_fill(0, $membersPerGroup, 'Murnel Teskar Vorlip ' . $oneGroupWords),
            ...array_fill(0, $membersPerGroup, 'Murnel Teskar Vorlip ' . $otherGroupWords),
            ...array_fill(0, 3, $oneGroupWords . ' ' . $otherGroupWords),
        ]);
    }

    /** @param list<string> $headlines */
    private function runOf(array $headlines): TrendingRun
    {
        return TrendingRuns::buildHeadlineRun(TrendingWindow::Day, $headlines, self::SAME_TIME);
    }
}
