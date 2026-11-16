<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\LinkCardContent;
use App\Entity\TrendingStory;
use App\Enum\TrendingWindow;
use App\Repository\TrendingStoryHistoryRepository;
use App\Service\Curation\Trending\Model\StoredStoryModel;
use App\Service\Curation\Trending\Model\StoryAssignmentModel;
use App\Service\Curation\Trending\Model\StoryBuildModel;
use App\Service\Curation\Trending\Model\StoryMemberModel;
use App\Service\Curation\Trending\Model\StoryMergeModel;
use App\Service\Curation\Trending\Model\TrendingStoryModel;
use App\Tests\DbTestCase;
use App\Tests\Support\SharedLinks;
use App\Tests\Support\TrendingFixtures;
use PHPUnit\Framework\Attributes\DataProvider;

final class TrendingStoryHistoryRepositoryTest extends DbTestCase
{
    private const string BUILT_AT = '2031-05-14 12:00:00';

    private TrendingFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtures = new TrendingFixtures($this->entityManager);
    }

    public function testAMergeMovesTheHistoryAndKeepsTheEarlierJoinOfASharedEntry(): void
    {
        [$one, $two, $three] = $this->entries(3);
        $survivor = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 10:00:00', $one);
        $this->fixtures->join($survivor, '2031-05-14 10:00:00', $two);
        $merged = $this->fixtures->story(TrendingWindow::Day, '2031-05-14 09:00:00', $two);
        $this->fixtures->join($merged, '2031-05-14 09:00:00', $three);

        $this->history()->merge(new StoryMergeModel($survivor->requireId(), $merged->requireId()));

        self::assertEquals(
            [
                [$survivor->requireId(), $one->requireId(), '2031-05-14 10:00:00', 0, 1],
                [$survivor->requireId(), $two->requireId(), '2031-05-14 09:00:00', 1, 0],
                [$survivor->requireId(), $three->requireId(), '2031-05-14 09:00:00', 0, 0],
            ],
            $this->members(),
        );
        self::assertSame([$survivor->requireId()], $this->storyIds());
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function storyTimesOfAMerge(): iterable
    {
        yield 'the merged story is newer' => [
            '2031-05-13 08:00:00',
            '2031-05-14 11:00:00',
            '2031-05-13 08:00:00',
            '2031-05-14 11:00:00',
        ];
        yield 'the merged story is older' => [
            '2031-05-12 08:00:00',
            '2031-05-13 11:00:00',
            '2031-05-12 08:00:00',
            '2031-05-14 10:00:00',
        ];
        yield 'the merged story lies inside the survivor' => [
            '2031-05-13 10:00:00',
            '2031-05-14 09:00:00',
            '2031-05-13 09:00:00',
            '2031-05-14 10:00:00',
        ];
    }

    #[DataProvider('storyTimesOfAMerge')]
    public function testAMergeWidensTheSurvivorsTimesToCoverTheMergedStory(
        string $mergedFirst,
        string $mergedLast,
        string $expectedFirst,
        string $expectedLast,
    ): void {
        [$one, $two, $three] = $this->entries(3);
        $survivor = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 10:00:00', $one);
        $merged = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 10:00:00', $two);
        $bystander = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 10:00:00', $three);
        $this->storyTimes($survivor, '2031-05-13 09:00:00', '2031-05-14 10:00:00');
        $this->storyTimes($merged, $mergedFirst, $mergedLast);
        $this->storyTimes($bystander, '2031-05-14 09:30:00', '2031-05-14 09:30:00');

        $this->history()->merge(new StoryMergeModel($survivor->requireId(), $merged->requireId()));

        self::assertSame(
            [
                [
                    'id' => $survivor->requireId(),
                    'first_seen' => $expectedFirst,
                    'last_seen' => $expectedLast,
                    'first_joined_at' => $expectedFirst,
                    'last_joined_at' => $expectedLast,
                ],
                [
                    'id' => $bystander->requireId(),
                    'first_seen' => '2031-05-14 09:30:00',
                    'last_seen' => '2031-05-14 09:30:00',
                    'first_joined_at' => '2031-05-14 09:30:00',
                    'last_joined_at' => '2031-05-14 09:30:00',
                ],
            ],
            $this->stories(),
        );
    }

    public function testAMergeKeepsTheLaterJoinOfTheSurvivorWhenItWasEarlier(): void
    {
        [$one, $two] = $this->entries(2);
        $survivor = $this->fixtures->story(TrendingWindow::Day, '2031-05-14 08:00:00', $one, $two);
        $merged = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 09:00:00', $two);

        $this->history()->merge(new StoryMergeModel($survivor->requireId(), $merged->requireId()));

        self::assertEquals(
            [
                [$survivor->requireId(), $one->requireId(), '2031-05-14 08:00:00', 1, 0],
                [$survivor->requireId(), $two->requireId(), '2031-05-14 08:00:00', 1, 1],
            ],
            $this->members(),
        );
    }

    public function testAMergeOfAnUnplacedLaterCopyLeavesTheSurvivorsRowAlone(): void
    {
        [$one, $two] = $this->entries(2);
        $survivor = $this->fixtures->story(TrendingWindow::Day, '2031-05-14 08:00:00', $one, $two);
        $merged = $this->fixtures->story(TrendingWindow::Day, '2031-05-14 07:00:00', $one);
        $this->fixtures->join($merged, '2031-05-14 09:00:00', $two);

        $this->history()->merge(new StoryMergeModel($survivor->requireId(), $merged->requireId()));

        self::assertEquals(
            [
                [$survivor->requireId(), $one->requireId(), '2031-05-14 07:00:00', 1, 0],
                [$survivor->requireId(), $two->requireId(), '2031-05-14 08:00:00', 1, 0],
            ],
            $this->members(),
        );
    }

    public function testAMergeKeepsOneFollowWithTheEarlierTimesAndOneHide(): void
    {
        [$one, $two] = $this->entries(2);
        $survivor = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 10:00:00', $one);
        $merged = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 10:00:00', $two);
        $reader = $this->fixtures->reader('both@example.com');
        $other = $this->fixtures->reader('other@example.com');
        $hider = $this->fixtures->reader('hider@example.com');
        $this->fixtures->follow($reader, $survivor, '2031-05-13 07:00:00');
        $this->fixtures->follow($reader, $merged, '2031-05-13 06:00:00');
        $this->opened($reader->requireId(), $survivor, '2031-05-14 12:00:00');
        $this->opened($reader->requireId(), $merged, '2031-05-14 08:00:00');
        $this->fixtures->hide($reader, $merged);
        $this->fixtures->follow($other, $merged, '2031-05-14 05:00:00');
        $this->fixtures->hide($hider, $survivor);
        $this->fixtures->hide($hider, $merged);
        $this->fixtures->follow($hider, $merged, '2031-05-14 04:00:00');

        $this->history()->merge(new StoryMergeModel($survivor->requireId(), $merged->requireId()));

        self::assertEquals(
            [
                [$reader->requireId(), $survivor->requireId(), '2031-05-13 06:00:00', '2031-05-14 08:00:00'],
                [$other->requireId(), $survivor->requireId(), '2031-05-14 05:00:00', '2031-05-14 05:00:00'],
                [$hider->requireId(), $survivor->requireId(), '2031-05-14 04:00:00', '2031-05-14 04:00:00'],
            ],
            $this->follows(),
        );
        self::assertEquals(
            [[$reader->requireId(), $survivor->requireId()], [$hider->requireId(), $survivor->requireId()]],
            $this->hides(),
        );
    }

    public function testAMergeKeepsTheSurvivorsEarlierFollowTimes(): void
    {
        [$one, $two] = $this->entries(2);
        $survivor = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 10:00:00', $one);
        $merged = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 10:00:00', $two);
        $reader = $this->fixtures->reader('both@example.com');
        $this->fixtures->follow($reader, $survivor, '2031-05-13 06:00:00');
        $this->fixtures->follow($reader, $merged, '2031-05-13 07:00:00');

        $this->history()->merge(new StoryMergeModel($survivor->requireId(), $merged->requireId()));

        self::assertEquals(
            [[$reader->requireId(), $survivor->requireId(), '2031-05-13 06:00:00', '2031-05-13 06:00:00']],
            $this->follows(),
        );
    }

    public function testStoredInWindowReadsOnlyMembersInsideTheWindow(): void
    {
        $feed = $this->fixtures->feed('window');
        $onTheStart = $this->fixtures->entry($feed, 'On the start', '2031-05-13 12:00:00');
        $before = $this->fixtures->entry($feed, 'Before', '2031-05-13 11:59:59');
        $inside = $this->fixtures->entry($feed, 'Inside', '2031-05-14 08:00:00');
        $later = $this->fixtures->entry($feed, 'Later', '2031-05-14 09:00:00');
        $mixed = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 10:00:00', $inside, $before, $onTheStart);
        $this->fixtures->story(TrendingWindow::Week, '2031-05-14 10:00:00', $before);
        $other = $this->fixtures->story(TrendingWindow::Day, '2031-05-14 10:00:00', $later);

        self::assertEquals(
            [
                new StoredStoryModel($mixed->requireId(), [$onTheStart->requireId(), $inside->requireId()]),
                new StoredStoryModel($other->requireId(), [$later->requireId()]),
            ],
            $this->history()->storedInWindow(self::utc('2031-05-13 12:00:00')),
        );
    }

    public function testStoredInWindowOrdersStoriesAndTheirMembersByIdWhateverOrderTheyJoinedIn(): void
    {
        [$one, $two, $three] = $this->entries(3);
        $earlier = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 10:00:00', $three);
        $later = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 10:00:00', $two);
        $this->fixtures->join($later, '2031-05-14 11:00:00', $one);
        $this->fixtures->join($earlier, '2031-05-14 11:00:00', $one);

        self::assertEquals(
            [
                new StoredStoryModel($earlier->requireId(), [$one->requireId(), $three->requireId()]),
                new StoredStoryModel($later->requireId(), [$one->requireId(), $two->requireId()]),
            ],
            $this->history()->storedInWindow(self::utc('2031-05-14 00:00:00')),
        );
    }

    public function testANewStoryJoinsItsMembersAtTheBuildAndPlacesThemInItsWindowOnly(): void
    {
        [$one, $two] = $this->entryIds(2);

        $storyId = $this->saveNew(TrendingWindow::Week, $this->group([$one, $two]));

        self::assertSame(
            [[
                'id' => $storyId,
                'first_seen' => '2031-05-14 08:00:00',
                'last_seen' => '2031-05-14 09:00:00',
                'first_joined_at' => self::BUILT_AT,
                'last_joined_at' => self::BUILT_AT,
            ]],
            $this->stories(),
        );
        self::assertEquals(
            [[$storyId, $one, self::BUILT_AT, 0, 1], [$storyId, $two, self::BUILT_AT, 0, 1]],
            $this->members(),
        );
    }

    public function testANewDayStoryIsPlacedInTheDay(): void
    {
        [$one, $two] = $this->entryIds(2);

        $storyId = $this->saveNew(TrendingWindow::Day, $this->group([$one, $two]));

        self::assertEquals(
            [[$storyId, $one, self::BUILT_AT, 1, 0], [$storyId, $two, self::BUILT_AT, 1, 0]],
            $this->members(),
        );
    }

    public function testAStoredStoryPlacesItsStayingMembersAndJoinsTheNewOnesAtTheBuild(): void
    {
        [$one, $two, $three] = $this->entries(3);
        $story = $this->fixtures->storyOfSites(
            TrendingWindow::Week,
            '2031-05-14 10:00:00',
            [[$one, 'kept.example'], [$two, null]],
        );
        $this->clearFlags();
        $bystander = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 10:00:00', $three);
        $this->clearFlags();

        $this->history()->save(
            new StoryAssignmentModel(
                $this->group([$one->requireId(), $two->requireId(), $three->requireId()], 'rewritten.example'),
                $story->requireId(),
                [$one->requireId(), $two->requireId()],
            ),
            self::build(TrendingWindow::Day),
        );

        self::assertEquals(
            [
                [$story->requireId(), $one->requireId(), '2031-05-14 10:00:00', 1, 0],
                [$story->requireId(), $two->requireId(), '2031-05-14 10:00:00', 1, 0],
                [$story->requireId(), $three->requireId(), self::BUILT_AT, 1, 0],
                [$bystander->requireId(), $three->requireId(), '2031-05-14 10:00:00', 0, 0],
            ],
            $this->members(),
        );
        self::assertSame(['kept.example', null, 'rewritten.example', null], $this->sites());
        self::assertSame(
            ['first_joined_at' => '2031-05-14 10:00:00', 'last_joined_at' => self::BUILT_AT],
            $this->joinTimes($story),
        );
        self::assertSame(
            ['first_joined_at' => '2031-05-14 10:00:00', 'last_joined_at' => '2031-05-14 10:00:00'],
            $this->joinTimes($bystander),
        );
    }

    public function testAWeekSaveMovesItsGroupsEntriesOutOfEveryOtherStoryWithTheEarlierJoinAndTheDay(): void
    {
        [$one, $two, $three] = $this->entries(3);
        $story = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 10:00:00', $one);
        $holder = $this->fixtures->story(TrendingWindow::Day, '2031-05-14 09:00:00', $two, $three);
        $older = $this->fixtures->story(TrendingWindow::Day, '2031-05-14 08:00:00', $one);
        $this->history()->clearPlacement(TrendingWindow::Week);

        $this->history()->save(
            new StoryAssignmentModel(
                $this->group([$one->requireId(), $two->requireId()]),
                $story->requireId(),
                [$one->requireId()],
            ),
            self::build(TrendingWindow::Week),
        );

        self::assertEquals(
            [
                [$story->requireId(), $one->requireId(), '2031-05-14 08:00:00', 1, 1],
                [$story->requireId(), $two->requireId(), '2031-05-14 09:00:00', 1, 1],
                [$holder->requireId(), $three->requireId(), '2031-05-14 09:00:00', 1, 0],
            ],
            $this->members(),
        );
        self::assertSame([$story->requireId(), $holder->requireId(), $older->requireId()], $this->storyIds());
    }

    public function testANewWeekStoryTakesItsEntriesFromTheStoryItSplitOffFrom(): void
    {
        [$one, $two, $three] = $this->entries(3);
        $original = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 10:00:00', $one, $two, $three);
        $this->history()->clearPlacement(TrendingWindow::Week);

        $storyId = $this->saveNew(TrendingWindow::Week, $this->group([$two->requireId(), $three->requireId()]));

        self::assertEquals(
            [
                [$original->requireId(), $one->requireId(), '2031-05-14 10:00:00', 0, 0],
                [$storyId, $two->requireId(), '2031-05-14 10:00:00', 0, 1],
                [$storyId, $three->requireId(), '2031-05-14 10:00:00', 0, 1],
            ],
            $this->members(),
        );
    }

    public function testAStoryEmptiedByAWeekSaveIsPrunedUnlessFollowed(): void
    {
        [$one, $two] = $this->entries(2);
        $story = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 10:00:00', $one);
        $emptied = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 10:00:00', $two);
        $followed = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 10:00:00', $two);
        $this->fixtures->follow($this->fixtures->reader('stays@example.com'), $followed, '2031-05-14 10:00:00');

        $this->history()->save(
            new StoryAssignmentModel(
                $this->group([$one->requireId(), $two->requireId()]),
                $story->requireId(),
                [$one->requireId()],
            ),
            self::build(TrendingWindow::Week),
        );
        $pruned = $this->history()->pruneUnfollowedOutside(self::utc('2031-05-07 12:00:00'));

        self::assertSame(1, $pruned);
        self::assertSame([$story->requireId(), $followed->requireId()], $this->storyIds());
        self::assertNotContains($emptied->requireId(), $this->storyIds());
    }

    public function testAStoredStoryWithNothingJoiningKeepsItsLastJoinedTime(): void
    {
        [$one, $two] = $this->entries(2);
        $story = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 10:00:00', $one, $two);

        $this->history()->save(
            new StoryAssignmentModel(
                $this->group([$one->requireId(), $two->requireId()]),
                $story->requireId(),
                [$one->requireId(), $two->requireId()],
            ),
            self::build(TrendingWindow::Week),
        );

        self::assertSame(
            ['first_joined_at' => '2031-05-14 10:00:00', 'last_joined_at' => '2031-05-14 10:00:00'],
            $this->joinTimes($story),
        );
    }

    public function testAStoredStoryPlacesOnlyTheMembersOfItsGroup(): void
    {
        [$one, $two] = $this->entries(2);
        $story = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 10:00:00', $one, $two);
        $this->clearFlags();

        $this->history()->save(
            new StoryAssignmentModel($this->group([$one->requireId()]), $story->requireId(), [$one->requireId()]),
            self::build(TrendingWindow::Week),
        );

        self::assertEquals(
            [
                [$story->requireId(), $one->requireId(), '2031-05-14 10:00:00', 0, 1],
                [$story->requireId(), $two->requireId(), '2031-05-14 10:00:00', 0, 0],
            ],
            $this->members(),
        );
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function boundsOfAGroup(): iterable
    {
        yield 'an earlier start widens the start only' => [
            '2031-05-14 07:00:00',
            '2031-05-14 09:00:00',
            '2031-05-14 07:00:00',
            '2031-05-14 10:00:00',
        ];
        yield 'a later end widens the end only' => [
            '2031-05-14 09:00:00',
            '2031-05-14 11:00:00',
            '2031-05-14 08:00:00',
            '2031-05-14 11:00:00',
        ];
    }

    #[DataProvider('boundsOfAGroup')]
    public function testAStoredStoryKeepsTheWiderOfItsBounds(
        string $groupFirstSeen,
        string $groupLastSeen,
        string $firstSeen,
        string $lastSeen,
    ): void {
        [$one] = $this->entries(1);
        $story = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 10:00:00', $one);
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE trending_story SET first_seen = ? WHERE id = ?',
            ['2031-05-14 08:00:00', $story->requireId()],
        );
        $group = new TrendingStoryModel(
            [new StoryMemberModel($one->requireId(), 101, null)],
            self::utc($groupFirstSeen),
            self::utc($groupLastSeen),
        );

        $this->history()->save(
            new StoryAssignmentModel($group, $story->requireId(), [$one->requireId()]),
            self::build(TrendingWindow::Week),
        );

        $row = $this->stories()[0];
        self::assertSame([$firstSeen, $lastSeen], [$row['first_seen'], $row['last_seen']]);
    }

    public function testMoreStayingMembersThanOneChunkAreAllPlaced(): void
    {
        $entries = $this->entries(301);
        $story = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 10:00:00', ...$entries);
        $this->clearFlags();
        $entryIds = array_map(static fn (Entry $entry): int => $entry->requireId(), $entries);

        $this->history()->save(
            new StoryAssignmentModel($this->group($entryIds), $story->requireId(), $entryIds),
            self::build(TrendingWindow::Week),
        );

        self::assertSame(301, $this->placedCount());
    }

    public function testAStoryLargerThanOneInsertChunkKeepsEveryMember(): void
    {
        $this->saveNew(TrendingWindow::Day, $this->group($this->entryIds(301)));

        self::assertSame(301, $this->placedCount());
    }

    public function testClearingAWindowsPlacementLeavesTheOtherWindowAndTheHistory(): void
    {
        [$one, $two] = $this->entries(2);
        $story = $this->fixtures->story(TrendingWindow::Day, '2031-05-14 10:00:00', $one, $two);
        $this->fixtures->place(TrendingWindow::Week, $story, $one);

        $this->history()->clearPlacement(TrendingWindow::Day);

        self::assertEquals(
            [
                [$story->requireId(), $one->requireId(), '2031-05-14 10:00:00', 0, 1],
                [$story->requireId(), $two->requireId(), '2031-05-14 10:00:00', 0, 0],
            ],
            $this->members(),
        );
    }

    public function testClearingTheWeekLeavesTheDay(): void
    {
        [$one] = $this->entries(1);
        $story = $this->fixtures->story(TrendingWindow::Day, '2031-05-14 10:00:00', $one);
        $this->fixtures->place(TrendingWindow::Week, $story, $one);

        $this->history()->clearPlacement(TrendingWindow::Week);

        self::assertEquals([[$story->requireId(), $one->requireId(), '2031-05-14 10:00:00', 1, 0]], $this->members());
    }

    public function testPruningDeletesUnfollowedStoriesWithNoMemberInTheWeek(): void
    {
        $feed = $this->fixtures->feed('prune');
        $old = $this->fixtures->entry($feed, 'Old', '2031-05-07 11:59:59');
        $onTheStart = $this->fixtures->entry($feed, 'On the start', '2031-05-07 12:00:00');
        $gone = $this->fixtures->story(TrendingWindow::Week, '2031-05-08 00:00:00', $old);
        $followed = $this->fixtures->story(TrendingWindow::Week, '2031-05-08 00:00:00', $old);
        $kept = $this->fixtures->story(TrendingWindow::Week, '2031-05-08 00:00:00', $old, $onTheStart);
        $empty = $this->fixtures->story(TrendingWindow::Week, '2031-05-08 00:00:00', $old);
        $this->entityManager->getConnection()
            ->executeStatement('DELETE FROM trending_story_entry WHERE story_id = ?', [$empty->requireId()]);
        $this->fixtures->follow($this->fixtures->reader('follower@example.com'), $followed, '2031-05-08 00:00:00');
        $this->fixtures->hide($this->fixtures->reader('hider@example.com'), $gone);

        $pruned = $this->history()->pruneUnfollowedOutside(self::utc('2031-05-07 12:00:00'));

        self::assertSame(2, $pruned);
        self::assertSame([$followed->requireId(), $kept->requireId()], $this->storyIds());
        self::assertSame([], $this->hides());
    }

    public function testAMemberRecordsItsLinkAndLosesItWhenTheLinkGoes(): void
    {
        [$one, $two] = $this->entryIds(2);
        $link = SharedLinks::of(
            $this->entityManager,
            new LinkCardContent('https://outlet.example/storm', 'Storm', null, null, 'Outlet'),
        );
        $this->saveNew(TrendingWindow::Day, new TrendingStoryModel(
            [
                new StoryMemberModel($one, 101, 'outlet.example', $link->requireId()),
                new StoryMemberModel($two, 101, null),
            ],
            self::utc('2031-05-14 08:00:00'),
            self::utc('2031-05-14 09:00:00'),
        ));

        self::assertEquals([$one => $link->requireId(), $two => null], $this->linksByEntry());

        $this->entityManager->getConnection()
            ->executeStatement('DELETE FROM shared_link WHERE id = ?', [$link->requireId()]);

        self::assertEquals([$one => null, $two => null], $this->linksByEntry());
    }

    public function testEachMemberKeepsItsArticleSiteCutToTheColumn(): void
    {
        [$one, $two, $three] = $this->entryIds(3);
        $longSite = str_repeat('ä', 250) . '.example';

        $this->saveNew(TrendingWindow::Day, new TrendingStoryModel(
            [
                new StoryMemberModel($one, 101, 'outlet.example'),
                new StoryMemberModel($two, 202, $longSite),
                new StoryMemberModel($three, 303, null),
            ],
            self::utc('2031-05-14 08:00:00'),
            self::utc('2031-05-14 09:00:00'),
        ));

        self::assertSame(['outlet.example', mb_substr($longSite, 0, 255), null], $this->sites());
    }

    public function testPurgingAnEntryRemovesItsStoryLink(): void
    {
        [$one, $two] = $this->entryIds(2);
        $this->saveNew(TrendingWindow::Day, $this->group([$one, $two]));

        $this->entityManager->getConnection()->executeStatement('DELETE FROM entry WHERE id = ?', [$one]);

        self::assertSame(1, $this->placedCount());
    }

    private function history(): TrendingStoryHistoryRepository
    {
        return new TrendingStoryHistoryRepository($this->entityManager);
    }

    private function saveNew(TrendingWindow $window, TrendingStoryModel $group): int
    {
        $this->history()->save(new StoryAssignmentModel($group, null, []), self::build($window));
        $storyId = $this->entityManager->getConnection()->fetchOne('SELECT MAX(id) FROM trending_story');
        self::assertIsNumeric($storyId);

        return (int) $storyId;
    }

    private static function build(TrendingWindow $window): StoryBuildModel
    {
        $builtAt = self::utc(self::BUILT_AT);

        return new StoryBuildModel($window, $window->startBefore($builtAt), $builtAt);
    }

    /** @param non-empty-list<int> $entryIds */
    private function group(array $entryIds, ?string $articleSite = null): TrendingStoryModel
    {
        return new TrendingStoryModel(
            array_map(
                static fn (int $entryId): StoryMemberModel => new StoryMemberModel($entryId, 101, $articleSite),
                $entryIds,
            ),
            self::utc('2031-05-14 08:00:00'),
            self::utc('2031-05-14 09:00:00'),
        );
    }

    /** @return non-empty-list<Entry> */
    private function entries(int $count): array
    {
        $feed = $this->fixtures->feed('history');
        $entries = [$this->fixtures->entry($feed, 'Entry 0', '2031-05-14 08:00:00')];
        for ($index = 1; $index < $count; ++$index) {
            $entries[] = $this->fixtures->entry($feed, 'Entry ' . $index, '2031-05-14 08:00:00');
        }
        $this->fixtures->flush();

        return $entries;
    }

    /** @return non-empty-list<int> */
    private function entryIds(int $count): array
    {
        return array_map(static fn (Entry $entry): int => $entry->requireId(), $this->entries($count));
    }

    private function opened(int $userId, TrendingStory $story, string $openedAt): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE trending_story_follow SET last_opened_at = ? WHERE user_id = ? AND story_id = ?',
            [$openedAt, $userId, $story->requireId()],
        );
    }

    private function storyTimes(TrendingStory $story, string $first, string $last): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE trending_story SET first_seen = ?, first_joined_at = ?, last_seen = ?, last_joined_at = ?'
            . ' WHERE id = ?',
            [$first, $first, $last, $last, $story->requireId()],
        );
    }

    private function clearFlags(): void
    {
        $this->entityManager->getConnection()
            ->executeStatement('UPDATE trending_story_entry SET in_day = 0, in_week = 0');
    }

    private static function utc(string $moment): \DateTimeImmutable
    {
        return new \DateTimeImmutable($moment, new \DateTimeZone('UTC'));
    }

    /** @return list<array{int, int, string, int, int}> */
    private function members(): array
    {
        /**
         * @var list<array{
         *     story_id: int|string, entry_id: int|string, joined_at: string,
         *     in_day: int|string|bool, in_week: int|string|bool,
         * }> $rows
         */
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT story_id, entry_id, joined_at, in_day, in_week FROM trending_story_entry'
            . ' ORDER BY story_id, entry_id',
        );

        return array_map(
            static fn (array $row): array => [
                (int) $row['story_id'],
                (int) $row['entry_id'],
                $row['joined_at'],
                (int) $row['in_day'],
                (int) $row['in_week'],
            ],
            $rows,
        );
    }

    /** @return list<array<string, mixed>> */
    private function stories(): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT id, first_seen, last_seen, first_joined_at, last_joined_at FROM trending_story ORDER BY id',
        );

        return array_map(
            static fn (array $row): array => ['id' => is_numeric($row['id']) ? (int) $row['id'] : $row['id']] + $row,
            $rows,
        );
    }

    /** @return list<int> */
    private function storyIds(): array
    {
        $storyIds = [];
        $connection = $this->entityManager->getConnection();
        foreach ($connection->fetchFirstColumn('SELECT id FROM trending_story ORDER BY id') as $storyId) {
            self::assertIsNumeric($storyId);
            $storyIds[] = (int) $storyId;
        }

        return $storyIds;
    }

    /** @return array<string, mixed> */
    private function joinTimes(TrendingStory $story): array
    {
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT first_joined_at, last_joined_at FROM trending_story WHERE id = ?',
            [$story->requireId()],
        );
        self::assertIsArray($row);

        return $row;
    }

    /** @return list<array{int, int, string, string}> */
    private function follows(): array
    {
        /**
         * @var list<array{
         *     user_id: int|string, story_id: int|string, followed_at: string, last_opened_at: string,
         * }> $rows
         */
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT user_id, story_id, followed_at, last_opened_at FROM trending_story_follow ORDER BY user_id',
        );

        return array_map(
            static fn (array $row): array => [
                (int) $row['user_id'],
                (int) $row['story_id'],
                $row['followed_at'],
                $row['last_opened_at'],
            ],
            $rows,
        );
    }

    /** @return list<array{int, int}> */
    private function hides(): array
    {
        /** @var list<array{user_id: int|string, story_id: int|string}> $rows */
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT user_id, story_id FROM trending_story_hide ORDER BY user_id',
        );

        return array_map(static fn (array $row): array => [(int) $row['user_id'], (int) $row['story_id']], $rows);
    }

    /** @return list<string|null> */
    private function sites(): array
    {
        /** @var list<string|null> $sites */
        $sites = $this->entityManager->getConnection()
            ->fetchFirstColumn('SELECT article_site FROM trending_story_entry ORDER BY story_id, entry_id');

        return $sites;
    }

    /** @return array<int, mixed> */
    private function linksByEntry(): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllKeyValue(
            'SELECT entry_id, shared_link_id FROM trending_story_entry ORDER BY entry_id',
        );

        return array_combine(array_map(intval(...), array_keys($rows)), array_values($rows));
    }

    private function placedCount(): int
    {
        $count = $this->entityManager->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM trending_story_entry WHERE in_day = 1 OR in_week = 1');
        self::assertIsNumeric($count);

        return (int) $count;
    }
}
