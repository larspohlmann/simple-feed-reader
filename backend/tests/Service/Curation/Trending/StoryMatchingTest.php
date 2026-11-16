<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending;

use App\Enum\TrendingWindow;
use App\Service\Curation\Trending\Model\StoredStoryModel;
use App\Service\Curation\Trending\Model\StoryAssignmentModel;
use App\Service\Curation\Trending\Model\StoryMatchModel;
use App\Service\Curation\Trending\Model\StoryMemberModel;
use App\Service\Curation\Trending\Model\StoryMergeModel;
use App\Service\Curation\Trending\Model\TrendingStoryModel;
use App\Service\Curation\Trending\StoryMatching;
use PHPUnit\Framework\TestCase;

final class StoryMatchingTest extends TestCase
{
    public function testAnUnchangedGroupKeepsItsStory(): void
    {
        $match = $this->matching()->match(
            [self::group(1, 2, 3)],
            [new StoredStoryModel(7, [1, 2, 3])],
            TrendingWindow::Week,
        );

        self::assertCount(1, $match->assignments);
        self::assertSame(7, $match->assignments[0]->storyId);
        self::assertSame([], $match->assignments[0]->joining());
        self::assertSame([1, 2, 3], $match->assignments[0]->storedEntryIds);
        self::assertSame(['kept' => 1, 'created' => 0, 'merged' => 0, 'split' => 0], $match->toLogContext());
    }

    public function testAGroupWithANewEntryKeepsItsStoryAndJoinsTheEntry(): void
    {
        $match = $this->matching()->match(
            [self::group(1, 2, 3, 4)],
            [new StoredStoryModel(7, [1, 2, 3])],
            TrendingWindow::Week,
        );

        self::assertSame(7, $match->assignments[0]->storyId);
        self::assertSame([4], self::entryIds($match->assignments[0]->joining()));
        self::assertSame([1, 2, 3], $match->assignments[0]->storedEntryIds);
    }

    public function testOverlapIsMeasuredAgainstTheSmallerSide(): void
    {
        $match = $this->matching()->match(
            [self::group(1, 2, 3, 4, 5, 6)],
            [new StoredStoryModel(7, [1, 2])],
            TrendingWindow::Week,
        );

        self::assertSame(7, $match->assignments[0]->storyId);
    }

    public function testBelowHalfIsANewStory(): void
    {
        $match = $this->matching()->match(
            [self::group(1, 2, 8, 9, 10)],
            [new StoredStoryModel(7, [1, 2, 3, 4, 5])],
            TrendingWindow::Week,
        );

        self::assertNull($match->assignments[0]->storyId);
        self::assertSame([], $match->assignments[0]->storedEntryIds);
        self::assertSame(1, $match->created());
        self::assertSame(0, $match->split);
    }

    public function testExactlyHalfMatches(): void
    {
        $match = $this->matching()->match(
            [self::group(1, 2, 8, 9)],
            [new StoredStoryModel(7, [1, 2, 3, 4])],
            TrendingWindow::Week,
        );

        self::assertSame(7, $match->assignments[0]->storyId);
    }

    public function testAStoryWithNoInWindowMembersMatchesNothing(): void
    {
        $match = $this->matching()->match(
            [self::group(1, 2)],
            [new StoredStoryModel(7, [])],
            TrendingWindow::Week,
        );

        self::assertNull($match->assignments[0]->storyId);
        self::assertSame(0, $match->split);
    }

    public function testAGroupSharingNothingIsNewAndNotASplit(): void
    {
        $match = $this->matching()->match(
            [self::group(1, 2)],
            [new StoredStoryModel(7, [3, 4])],
            TrendingWindow::Week,
        );

        self::assertNull($match->assignments[0]->storyId);
        self::assertSame(0, $match->split);
    }

    public function testASplitGivesTheLargerPartTheIdAndTheSmallerANewStory(): void
    {
        $match = $this->matching()->match(
            [self::group(1, 2), self::group(3, 4, 5, 6)],
            [new StoredStoryModel(7, [1, 2, 3, 4, 5, 6])],
            TrendingWindow::Week,
        );

        self::assertNull($match->assignments[0]->storyId);
        self::assertSame(7, $match->assignments[1]->storyId);
        self::assertSame(1, $match->split);
        self::assertSame(0, $match->merged());
    }

    public function testAWeekGroupOverTwoStoriesMergesTheSmallerIntoTheLarger(): void
    {
        $match = $this->matching()->match(
            [self::group(1, 2, 3, 4, 5)],
            [new StoredStoryModel(7, [1, 2, 3]), new StoredStoryModel(9, [4, 5])],
            TrendingWindow::Week,
        );

        self::assertSame(7, $match->assignments[0]->storyId);
        self::assertEquals([new StoryMergeModel(7, 9)], $match->merges);
        self::assertSame([1, 2, 3, 4, 5], $match->assignments[0]->storedEntryIds);
        self::assertSame([], $match->assignments[0]->joining());
        self::assertSame(1, $match->merged());
    }

    public function testADayGroupOverTwoStoriesAttachesToTheBestAndMergesNothing(): void
    {
        $match = $this->matching()->match(
            [self::group(1, 2, 3, 4, 5)],
            [new StoredStoryModel(7, [1, 2, 3]), new StoredStoryModel(9, [4, 5])],
            TrendingWindow::Day,
        );

        self::assertSame(7, $match->assignments[0]->storyId);
        self::assertSame([], $match->merges);
        self::assertSame([1, 2, 3], $match->assignments[0]->storedEntryIds);
        self::assertSame([4, 5], self::entryIds($match->assignments[0]->joining()));
    }

    public function testAStoryAnotherGroupAlreadyTookIsNotMerged(): void
    {
        $match = $this->matching()->match(
            [self::group(1, 2, 3), self::group(4, 5)],
            [new StoredStoryModel(7, [1, 2, 3]), new StoredStoryModel(9, [4, 5])],
            TrendingWindow::Week,
        );

        self::assertSame([7, 9], self::storyIds($match));
        self::assertSame([], $match->merges);
    }

    public function testAStoredStoryBelowTheThresholdIsNotMerged(): void
    {
        $match = $this->matching()->match(
            [self::group(1, 2, 3, 4, 5)],
            [new StoredStoryModel(7, [1, 2, 3]), new StoredStoryModel(9, [4, 8, 9, 10])],
            TrendingWindow::Week,
        );

        self::assertSame(7, $match->assignments[0]->storyId);
        self::assertSame([], $match->merges);
        self::assertSame([4, 5], self::entryIds($match->assignments[0]->joining()));
    }

    public function testTiesGoToTheStoryWithMoreInWindowMembersThenTheOlderId(): void
    {
        $sameSize = $this->matching()->match(
            [self::group(1, 2)],
            [new StoredStoryModel(9, [1, 2]), new StoredStoryModel(7, [1, 2])],
            TrendingWindow::Day,
        );
        $largerWins = $this->matching()->match(
            [self::group(1, 2)],
            [new StoredStoryModel(9, [1, 2, 3]), new StoredStoryModel(7, [1, 2])],
            TrendingWindow::Day,
        );

        self::assertSame(7, $sameSize->assignments[0]->storyId);
        self::assertSame(9, $largerWins->assignments[0]->storyId);
    }

    public function testWeekAssignmentIsOneToOne(): void
    {
        $match = $this->matching()->match(
            [self::group(1, 2), self::group(3, 4)],
            [new StoredStoryModel(7, [1, 2, 3, 4])],
            TrendingWindow::Week,
        );

        self::assertSame([7, null], self::storyIds($match));
        self::assertSame(1, $match->split);
    }

    public function testAWeekGroupLeftWithoutItsBestStoryTakesTheNextItReaches(): void
    {
        $match = $this->matching()->match(
            [self::group(1, 2, 3), self::group(4, 5)],
            [new StoredStoryModel(7, [1, 2, 3, 4, 5]), new StoredStoryModel(9, [5, 6])],
            TrendingWindow::Week,
        );

        self::assertSame([7, 9], self::storyIds($match));
        self::assertSame(0, $match->split);
    }

    public function testAWeekGroupOverThreeStoriesMergesBothOthersIntoTheLargest(): void
    {
        $match = $this->matching()->match(
            [self::group(1, 2, 3, 4, 5, 6)],
            [new StoredStoryModel(11, [6]), new StoredStoryModel(9, [4, 5]), new StoredStoryModel(7, [1, 2, 3])],
            TrendingWindow::Week,
        );

        self::assertSame([7], self::storyIds($match));
        self::assertEquals([new StoryMergeModel(7, 9), new StoryMergeModel(7, 11)], $match->merges);
    }

    public function testASplitIsCountedOncePerGroupHoweverManyStoriesItReaches(): void
    {
        $match = $this->matching()->match(
            [self::group(1, 2, 3, 4), self::group(5, 6)],
            [new StoredStoryModel(7, [1, 2, 3, 4, 5, 6]), new StoredStoryModel(9, [3, 4])],
            TrendingWindow::Week,
        );

        self::assertSame([7, null], self::storyIds($match));
        self::assertEquals([new StoryMergeModel(7, 9)], $match->merges);
        self::assertSame(1, $match->split);
    }

    public function testAGroupGoesToTheStoryItOverlapsMostBeforeTheOneItSharesMostWith(): void
    {
        $match = $this->matching()->match(
            [self::group(1, 2, 3, 4, 5, 6)],
            [new StoredStoryModel(9, [3, 4, 5, 8, 9, 10, 11, 12]), new StoredStoryModel(7, [1, 2])],
            TrendingWindow::Day,
        );

        self::assertSame([7], self::storyIds($match));
    }

    public function testDayGroupsSharingTheirBestStoryAllAttachToItAndNothingSplits(): void
    {
        $match = $this->matching()->match(
            [self::group(1, 2), self::group(3, 4, 5)],
            [new StoredStoryModel(7, [1, 2, 3, 4]), new StoredStoryModel(9, [5, 6])],
            TrendingWindow::Day,
        );

        self::assertSame([7, 7], self::storyIds($match));
        self::assertSame([1, 2, 3, 4], $match->assignments[1]->storedEntryIds);
        self::assertSame([5], self::entryIds($match->assignments[1]->joining()));
        self::assertSame(['kept' => 2, 'created' => 0, 'merged' => 0, 'split' => 0], $match->toLogContext());
    }

    public function testADayGroupBelowTheThresholdOfEveryStoryStillGetsANewStory(): void
    {
        $match = $this->matching()->match(
            [self::group(1, 2, 3), self::group(4, 8, 9)],
            [new StoredStoryModel(7, [1, 2, 3, 4, 5, 6])],
            TrendingWindow::Day,
        );

        self::assertSame([7, null], self::storyIds($match));
        self::assertSame(['kept' => 1, 'created' => 1, 'merged' => 0, 'split' => 0], $match->toLogContext());
    }

    public function testAnEntryTwoStoriesHoldCountsTowardBoth(): void
    {
        $match = $this->matching()->match(
            [self::group(2, 3, 4)],
            [new StoredStoryModel(7, [1, 2]), new StoredStoryModel(9, [2, 3])],
            TrendingWindow::Week,
        );

        self::assertSame(9, $match->assignments[0]->storyId);
        self::assertEquals([new StoryMergeModel(9, 7)], $match->merges);
    }

    public function testTheOutcomeDoesNotDependOnInputOrder(): void
    {
        $splitGroups = [self::group(1, 2), self::group(3, 4, 5, 6)];
        $splitStored = [new StoredStoryModel(7, [1, 2, 3, 4, 5, 6])];
        $mergeGroups = [self::group(1, 2, 3, 4, 5)];
        $mergeStored = [new StoredStoryModel(7, [1, 2, 3]), new StoredStoryModel(9, [4, 5])];

        $split = $this->matching()->match($splitGroups, $splitStored, TrendingWindow::Week);
        $merge = $this->matching()->match($mergeGroups, $mergeStored, TrendingWindow::Week);
        $mergeReversed = $this->matching()->match($mergeGroups, array_reverse($mergeStored), TrendingWindow::Week);
        $splitReversed = $this->matching()->match(array_reverse($splitGroups), $splitStored, TrendingWindow::Week);

        self::assertEquals($merge, $mergeReversed);
        self::assertSame(
            array_reverse(self::storyIds($split)),
            self::storyIds($splitReversed),
        );
        self::assertSame($split->split, $splitReversed->split);
    }

    public function testAWeekGroupClaimsOnlyTheEntriesAnotherStoryHolds(): void
    {
        $match = $this->matching()->match(
            [self::group(1, 2, 3, 4), self::group(5)],
            [new StoredStoryModel(7, [1, 2, 3]), new StoredStoryModel(9, [4, 5])],
            TrendingWindow::Week,
        );

        self::assertSame(7, $match->assignments[0]->storyId);
        self::assertSame([4], $match->assignments[0]->claimedEntryIds);
        self::assertSame([], $match->assignments[1]->claimedEntryIds);
    }

    public function testAWeekGroupClaimsNothingFromAStoryMergedIntoItsOwn(): void
    {
        $match = $this->matching()->match(
            [self::group(1, 2, 3, 4, 5)],
            [new StoredStoryModel(7, [1, 2, 3]), new StoredStoryModel(9, [4, 5])],
            TrendingWindow::Week,
        );

        self::assertEquals([new StoryMergeModel(7, 9)], $match->merges);
        self::assertSame([], $match->assignments[0]->claimedEntryIds);
    }

    public function testAWeekGroupClaimsWhatAStoryMergedIntoAnotherHolds(): void
    {
        $match = $this->matching()->match(
            [self::group(1, 2, 3, 4, 5, 6), self::group(7, 8, 9)],
            [
                new StoredStoryModel(11, [1, 2, 3, 4, 5]),
                new StoredStoryModel(12, [7, 8, 9]),
                new StoredStoryModel(13, [6, 7, 8]),
            ],
            TrendingWindow::Week,
        );

        self::assertEquals([new StoryMergeModel(12, 13)], $match->merges);
        self::assertSame([6], $match->assignments[0]->claimedEntryIds);
        self::assertSame([], $match->assignments[1]->claimedEntryIds);
    }

    public function testANewWeekStoryClaimsEveryEntryAStoredStoryHolds(): void
    {
        $match = $this->matching()->match(
            [self::group(1, 2, 8, 9, 10)],
            [new StoredStoryModel(7, [1, 2, 3, 4, 5])],
            TrendingWindow::Week,
        );

        self::assertNull($match->assignments[0]->storyId);
        self::assertSame([1, 2], $match->assignments[0]->claimedEntryIds);
    }

    public function testTheDayNeverClaims(): void
    {
        $match = $this->matching()->match(
            [self::group(1, 2, 3, 4, 5), self::group(8, 9, 10)],
            [new StoredStoryModel(7, [1, 2, 3]), new StoredStoryModel(9, [4, 5, 8])],
            TrendingWindow::Day,
        );

        self::assertSame([4, 5], self::entryIds($match->assignments[0]->joining()));
        self::assertSame([], $match->assignments[0]->claimedEntryIds);
        self::assertSame([], $match->assignments[1]->claimedEntryIds);
    }

    private function matching(): StoryMatching
    {
        return new StoryMatching();
    }

    private static function group(int $first, int ...$others): TrendingStoryModel
    {
        $members = array_values(array_map(
            static fn (int $entryId): StoryMemberModel => new StoryMemberModel($entryId, $entryId, null),
            [$first, ...$others],
        ));
        $moment = new \DateTimeImmutable('2031-05-14 12:00:00');

        return new TrendingStoryModel($members, $moment, $moment);
    }

    /**
     * @param list<StoryMemberModel> $members
     *
     * @return list<int>
     */
    private static function entryIds(array $members): array
    {
        return array_map(static fn (StoryMemberModel $member): int => $member->entryId, $members);
    }

    /** @return list<int|null> */
    private static function storyIds(StoryMatchModel $match): array
    {
        return array_map(
            static fn (StoryAssignmentModel $assignment): ?int => $assignment->storyId,
            $match->assignments,
        );
    }
}
