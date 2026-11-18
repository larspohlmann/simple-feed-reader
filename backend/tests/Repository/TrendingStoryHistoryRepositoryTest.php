<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\LinkCardContent;
use App\Entity\TrendingStory;
use App\Entity\User;
use App\Enum\TrendingWindow;
use App\Repository\TrendingStoryHistoryRepository;
use App\Service\Curation\Trending\Model\StoredStoryModel;
use App\Service\Curation\Trending\Model\StoryAssignmentModel;
use App\Service\Curation\Trending\Model\StoryBuildModel;
use App\Service\Curation\Trending\Model\StoryMemberModel;
use App\Service\Curation\Trending\Model\StoryMergeModel;
use App\Service\Curation\Trending\Model\TrendingStoryModel;
use App\Tests\DbTestCase;
use App\Tests\Support\QueryRecorder;
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
            $this->history()->storedInWindow($this->ownerId(), self::utc('2031-05-13 12:00:00')),
        );
    }

    public function testStoredStoriesCarryTheirFallbackMember(): void
    {
        $feed = $this->fixtures->feed('fallback');
        $entry = $this->fixtures->entry($feed, 'Titled', '2031-05-14 08:00:00');
        $other = $this->fixtures->entry($feed, 'Untitled', '2031-05-14 08:30:00');
        $titled = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 10:00:00', $entry);
        $untitled = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 10:00:00', $other);
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE trending_story SET fallback_entry_id = ?, fallback_title = ? WHERE id = ?',
            [$entry->requireId(), 'Hafenstreik', $titled->requireId()],
        );

        $stored = $this->history()->storedInWindow($this->ownerId(), self::utc('2031-05-13 12:00:00'));

        self::assertEquals(
            [
                new StoredStoryModel($titled->requireId(), [$entry->requireId()], $entry->requireId(), true),
                new StoredStoryModel($untitled->requireId(), [$other->requireId()], null, false),
            ],
            $stored,
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
            $this->history()->storedInWindow($this->ownerId(), self::utc('2031-05-14 00:00:00')),
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

    public function testAStoredStoryPlacesItsStayingMembersAndJoinsTheOthers(): void
    {
        [$one, $two, $three] = $this->entries(3);
        $story = $this->fixtures->storyOfSites(
            TrendingWindow::Week,
            '2031-05-14 10:00:00',
            [[$one, 'kept.example'], [$two, null]],
        );
        $this->clearPlacements();
        $bystander = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 10:00:00', $three);
        $this->clearPlacements();

        $this->saveAndPlace(
            new StoryAssignmentModel(
                $this->group([$one->requireId(), $two->requireId(), $three->requireId()], 'rewritten.example'),
                $story->requireId(),
                [$one->requireId(), $two->requireId()],
            ),
            TrendingWindow::Day,
        );

        self::assertEquals(
            [
                [$story->requireId(), $one->requireId(), '2031-05-14 10:00:00', 1, 0],
                [$story->requireId(), $two->requireId(), '2031-05-14 10:00:00', 1, 0],
                [$story->requireId(), $three->requireId(), '2031-05-14 10:00:00', 1, 0],
                [$bystander->requireId(), $three->requireId(), '2031-05-14 10:00:00', 0, 0],
            ],
            $this->members(),
        );
        self::assertSame(['kept.example', null, 'rewritten.example', null], $this->sites());
        self::assertSame(
            ['first_joined_at' => '2031-05-14 10:00:00', 'last_joined_at' => '2031-05-14 10:00:00'],
            $this->joinTimes($story),
        );
        self::assertSame(
            ['first_joined_at' => '2031-05-14 10:00:00', 'last_joined_at' => '2031-05-14 10:00:00'],
            $this->joinTimes($bystander),
        );
    }

    public function testAnEntryJoiningASecondStoryKeepsItsEarliestJoin(): void
    {
        [$one, $regrouped, $fresh] = $this->entries(3);
        $story = $this->fixtures->story(TrendingWindow::Day, '2031-05-14 10:00:00', $one);
        $later = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 11:00:00', $regrouped);
        $earlier = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 09:00:00', $regrouped);
        $this->clearPlacements();

        $this->save(
            new StoryAssignmentModel(
                $this->group([$one->requireId(), $regrouped->requireId(), $fresh->requireId()]),
                $story->requireId(),
                [$one->requireId()],
            ),
            $this->build(TrendingWindow::Day),
        );

        self::assertEquals(
            [
                [$story->requireId(), $one->requireId(), '2031-05-14 10:00:00', 0, 0],
                [$story->requireId(), $regrouped->requireId(), '2031-05-14 09:00:00', 0, 0],
                [$story->requireId(), $fresh->requireId(), self::BUILT_AT, 0, 0],
                [$later->requireId(), $regrouped->requireId(), '2031-05-14 11:00:00', 0, 0],
                [$earlier->requireId(), $regrouped->requireId(), '2031-05-14 09:00:00', 0, 0],
            ],
            $this->members(),
        );
    }

    public function testAWeekSaveMovesItsGroupsEntriesOutOfEveryOtherStoryWithTheEarlierJoinAndTheDay(): void
    {
        [$one, $two, $three] = $this->entries(3);
        $story = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 10:00:00', $one);
        $holder = $this->fixtures->story(TrendingWindow::Day, '2031-05-14 09:00:00', $two, $three);
        $older = $this->fixtures->story(TrendingWindow::Day, '2031-05-14 08:00:00', $one);

        $this->saveAndPlace(
            new StoryAssignmentModel(
                $this->group([$one->requireId(), $two->requireId()]),
                $story->requireId(),
                [$one->requireId()],
                [$one->requireId(), $two->requireId()],
            ),
            TrendingWindow::Week,
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

        $splitOff = [$two->requireId(), $three->requireId()];
        $storyId = $this->saveAndPlace(
            new StoryAssignmentModel($this->group($splitOff), null, [], $splitOff),
            TrendingWindow::Week,
        );

        self::assertEquals(
            [
                [$original->requireId(), $one->requireId(), '2031-05-14 10:00:00', 0, 0],
                [$storyId, $two->requireId(), '2031-05-14 10:00:00', 0, 1],
                [$storyId, $three->requireId(), '2031-05-14 10:00:00', 0, 1],
            ],
            $this->members(),
        );
    }

    public function testAWeekSaveLeavesAnEntryItDoesNotClaimInTheOtherStory(): void
    {
        [$one, $two] = $this->entries(2);
        $story = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 10:00:00', $one);
        $holder = $this->fixtures->story(TrendingWindow::Day, '2031-05-14 09:00:00', $two);

        $this->saveAndPlace(
            new StoryAssignmentModel(
                $this->group([$one->requireId(), $two->requireId()]),
                $story->requireId(),
                [$one->requireId()],
            ),
            TrendingWindow::Week,
        );

        self::assertEquals(
            [
                [$story->requireId(), $one->requireId(), '2031-05-14 10:00:00', 0, 1],
                [$story->requireId(), $two->requireId(), '2031-05-14 09:00:00', 0, 1],
                [$holder->requireId(), $two->requireId(), '2031-05-14 09:00:00', 1, 0],
            ],
            $this->members(),
        );
    }

    public function testABuildReadsTheEarliestJoinsOfEveryJoiningEntryOnce(): void
    {
        [$one, $two, $three, $four] = $this->entries(4);
        $first = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 10:00:00', $one);
        $second = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 10:00:00', $three);
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        self::assertInstanceOf(QueryRecorder::class, $recorder);
        $recorder->reset();

        $this->history()->saveAll($this->build(TrendingWindow::Week), [
            new StoryAssignmentModel(
                $this->group([$one->requireId(), $two->requireId()]),
                $first->requireId(),
                [$one->requireId()],
            ),
            new StoryAssignmentModel(
                $this->group([$three->requireId(), $four->requireId()]),
                $second->requireId(),
                [$three->requireId()],
            ),
        ]);

        self::assertCount(1, $recorder->queriesMatching('MIN(joined_at)'));
    }

    public function testASaveWithNothingToClaimReadsNoOtherStory(): void
    {
        [$one, $two] = $this->entries(2);
        $story = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 10:00:00', $one);
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        self::assertInstanceOf(QueryRecorder::class, $recorder);
        $recorder->reset();

        $this->save(
            new StoryAssignmentModel(
                $this->group([$one->requireId(), $two->requireId()]),
                $story->requireId(),
                [$one->requireId()],
            ),
            $this->build(TrendingWindow::Week),
        );

        self::assertNotSame([], $recorder->queriesMatching('trending_story_entry'));
        self::assertSame([], $recorder->queriesMatching('story_id <>'));
    }

    public function testAStoryEmptiedByAWeekSaveIsPrunedUnlessFollowed(): void
    {
        [$one, $two] = $this->entries(2);
        $story = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 10:00:00', $one);
        $emptied = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 10:00:00', $two);
        $followed = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 10:00:00', $two);
        $this->fixtures->follow($this->fixtures->reader('stays@example.com'), $followed, '2031-05-14 10:00:00');

        $this->save(
            new StoryAssignmentModel(
                $this->group([$one->requireId(), $two->requireId()]),
                $story->requireId(),
                [$one->requireId()],
                [$two->requireId()],
            ),
            $this->build(TrendingWindow::Week),
        );
        $pruned = $this->history()->pruneUnfollowedOutside($this->build(TrendingWindow::Week));

        self::assertSame(1, $pruned);
        self::assertSame([$story->requireId(), $followed->requireId()], $this->storyIds());
        self::assertNotContains($emptied->requireId(), $this->storyIds());
    }

    public function testAStoredStoryWithNothingJoiningKeepsItsLastJoinedTime(): void
    {
        [$one, $two] = $this->entries(2);
        $story = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 10:00:00', $one, $two);

        $this->save(
            new StoryAssignmentModel(
                $this->group([$one->requireId(), $two->requireId()]),
                $story->requireId(),
                [$one->requireId(), $two->requireId()],
            ),
            $this->build(TrendingWindow::Week),
        );

        self::assertSame(
            ['first_joined_at' => '2031-05-14 10:00:00', 'last_joined_at' => '2031-05-14 10:00:00'],
            $this->joinTimes($story),
        );
    }

    public function testAStoredStoryWhoseOnlyJoiningMemberIsNewMovesItsLastJoinToTheBuild(): void
    {
        [$one, $fresh] = $this->entries(2);
        $story = $this->fixtures->story(TrendingWindow::Day, '2031-05-14 10:00:00', $one);

        $this->save(
            new StoryAssignmentModel(
                $this->group([$one->requireId(), $fresh->requireId()]),
                $story->requireId(),
                [$one->requireId()],
            ),
            $this->build(TrendingWindow::Day),
        );

        self::assertSame(
            ['first_joined_at' => '2031-05-14 10:00:00', 'last_joined_at' => self::BUILT_AT],
            $this->joinTimes($story),
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function earlierJoinsOfARegroupedEntry(): iterable
    {
        yield 'joined before the story last joined' => ['2031-05-14 09:00:00', '2031-05-14 10:00:00'];
        yield 'joined after the story last joined' => ['2031-05-14 11:00:00', '2031-05-14 11:00:00'];
    }

    #[DataProvider('earlierJoinsOfARegroupedEntry')]
    public function testAStoredStoryWhoseOnlyJoiningMemberRegroupedMovesItsLastJoinOnlyToThatJoin(
        string $heldSince,
        string $lastJoinedAt,
    ): void {
        [$one, $regrouped] = $this->entries(2);
        $story = $this->fixtures->story(TrendingWindow::Day, '2031-05-14 10:00:00', $one);
        $this->fixtures->story(TrendingWindow::Week, $heldSince, $regrouped);

        $this->save(
            new StoryAssignmentModel(
                $this->group([$one->requireId(), $regrouped->requireId()]),
                $story->requireId(),
                [$one->requireId()],
            ),
            $this->build(TrendingWindow::Day),
        );

        self::assertSame(
            ['first_joined_at' => '2031-05-14 10:00:00', 'last_joined_at' => $lastJoinedAt],
            $this->joinTimes($story),
        );
    }

    public function testAStoredStoryPlacesOnlyTheMembersOfItsGroup(): void
    {
        [$one, $two] = $this->entries(2);
        $story = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 10:00:00', $one, $two);
        $this->clearPlacements();

        $this->saveAndPlace(
            new StoryAssignmentModel($this->group([$one->requireId()]), $story->requireId(), [$one->requireId()]),
            TrendingWindow::Week,
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

        $this->save(
            new StoryAssignmentModel($group, $story->requireId(), [$one->requireId()]),
            $this->build(TrendingWindow::Week),
        );

        $row = $this->stories()[0];
        self::assertSame([$firstSeen, $lastSeen], [$row['first_seen'], $row['last_seen']]);
    }

    public function testSavingAStoredStoryLeavesTheTimesOfEveryOtherStory(): void
    {
        [$one, $two] = $this->entries(2);
        $saved = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 10:00:00', $one);
        $other = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 10:00:00', $two);
        $this->storyTimes($other, '2031-05-14 09:00:00', '2031-05-14 09:30:00');
        $before = $this->stories()[1];
        $group = new TrendingStoryModel(
            [new StoryMemberModel($one->requireId(), 101, null)],
            self::utc('2031-05-14 07:00:00'),
            self::utc('2031-05-14 11:00:00'),
        );

        $this->save(
            new StoryAssignmentModel($group, $saved->requireId(), [$one->requireId()]),
            $this->build(TrendingWindow::Week),
        );

        self::assertSame($before, $this->stories()[1]);
    }

    public function testMoreStayingMembersThanOneChunkAreAllPlaced(): void
    {
        $entries = $this->entries(301);
        $story = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 10:00:00', ...$entries);
        $this->clearPlacements();
        $entryIds = array_map(static fn (Entry $entry): int => $entry->requireId(), $entries);

        $this->saveAndPlace(
            new StoryAssignmentModel($this->group($entryIds), $story->requireId(), $entryIds),
            TrendingWindow::Week,
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

        $this->history()->place($this->build(TrendingWindow::Day), []);

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

        $this->history()->place($this->build(TrendingWindow::Week), []);

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

        $pruned = $this->history()->pruneUnfollowedOutside($this->build(TrendingWindow::Week));

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

    public function testPlacingAWindowWritesItsRowsAndLeavesTheOtherWindow(): void
    {
        [$one, $two] = $this->entries(2);
        $story = $this->fixtures->story(TrendingWindow::Day, '2031-05-14 10:00:00', $one, $two);

        $written = $this->history()->place(
            $this->build(TrendingWindow::Week),
            [$one->requireId() => $story->requireId()],
        );

        self::assertSame(1, $written);
        self::assertSame([$one->requireId()], $this->placedEntries(TrendingWindow::Week, $story->requireId()));
        self::assertSame(
            [$one->requireId(), $two->requireId()],
            $this->placedEntries(TrendingWindow::Day, $story->requireId()),
        );
    }

    public function testPlacingTheSamePlacementAgainWritesNothing(): void
    {
        [$one, $two] = $this->entries(2);
        $story = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 10:00:00', $one, $two);
        $placement = [$one->requireId() => $story->requireId(), $two->requireId() => $story->requireId()];
        $this->history()->place($this->build(TrendingWindow::Day), $placement);

        self::assertSame(0, $this->history()->place($this->build(TrendingWindow::Day), $placement));
        self::assertSame(
            [$one->requireId(), $two->requireId()],
            $this->placedEntries(TrendingWindow::Day, $story->requireId()),
        );
    }

    public function testPlacingMovesAnEntryToItsNewStoryAndDropsOneNoLongerGrouped(): void
    {
        [$one, $two, $three] = $this->entries(3);
        $first = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 10:00:00', $one, $two);
        $second = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 10:00:00', $three);
        $this->fixtures->join($second, '2031-05-14 11:00:00', $two);

        $written = $this->history()->place(
            $this->build(TrendingWindow::Week),
            [$one->requireId() => $first->requireId(), $two->requireId() => $second->requireId()],
        );

        self::assertSame(2, $written);
        self::assertSame([$one->requireId()], $this->placedEntries(TrendingWindow::Week, $first->requireId()));
        self::assertSame([$two->requireId()], $this->placedEntries(TrendingWindow::Week, $second->requireId()));
    }

    public function testAMergeMovesTheMergedStorysDayPlacementToTheSurvivor(): void
    {
        [$one, $two] = $this->entries(2);
        $survivor = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 10:00:00', $one);
        $merged = $this->fixtures->story(TrendingWindow::Day, '2031-05-14 09:00:00', $one, $two);

        $this->history()->merge(new StoryMergeModel($survivor->requireId(), $merged->requireId()));

        self::assertSame(
            [$one->requireId(), $two->requireId()],
            $this->placedEntries(TrendingWindow::Day, $survivor->requireId()),
        );
        self::assertSame([$one->requireId()], $this->placedEntries(TrendingWindow::Week, $survivor->requireId()));
        self::assertSame(3, $this->placedCount());
    }

    public function testAClaimTakesTheEarlierJoinAndTheOtherStorysPlacementOfTheClaimedEntriesOnly(): void
    {
        [$one, $two] = $this->entries(2);
        $story = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 10:00:00', $one);
        $other = $this->fixtures->story(TrendingWindow::Day, '2031-05-14 08:00:00', $one, $two);

        $this->save(
            new StoryAssignmentModel(
                $this->group([$one->requireId()]),
                $story->requireId(),
                [$one->requireId()],
                [$one->requireId()],
            ),
            $this->build(TrendingWindow::Week),
        );

        self::assertEquals(
            [
                [$story->requireId(), $one->requireId(), '2031-05-14 08:00:00', 1, 1],
                [$other->requireId(), $two->requireId(), '2031-05-14 08:00:00', 1, 0],
            ],
            $this->members(),
        );
    }

    public function testTwoUsersHoldTheSameEntriesInSeparateStories(): void
    {
        [$one, $two] = $this->entryIds(2);
        $owner = $this->fixtures->owner();
        $other = $this->fixtures->reader('other@example.com');

        $ownerStoryId = $this->saveNewFor($owner, $this->group([$one, $two]));
        $otherStoryId = $this->saveNewFor($other, $this->group([$one, $two]));

        self::assertSame(
            [[$ownerStoryId, $owner->requireId()], [$otherStoryId, $other->requireId()]],
            $this->storyOwners(),
        );
        self::assertCount(4, $this->members());
        self::assertSame(
            [
                [$owner->requireId(), $one, $ownerStoryId],
                [$owner->requireId(), $two, $ownerStoryId],
                [$other->requireId(), $one, $otherStoryId],
                [$other->requireId(), $two, $otherStoryId],
            ],
            $this->placements(),
        );
        self::assertEquals(
            [new StoredStoryModel($ownerStoryId, [$one, $two])],
            $this->history()->storedInWindow($owner->requireId(), self::utc('2031-05-14 00:00:00')),
        );
    }

    public function testAClaimNeverTakesAnotherUsersMember(): void
    {
        [$one, $claimed] = $this->entries(2);
        $other = $this->fixtures->reader('other@example.com');
        $story = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 10:00:00', $one);
        $othersStory = $this->fixtures->storyOf($other, TrendingWindow::Week, '2031-05-14 09:00:00', $claimed);

        $this->saveAndPlace(
            new StoryAssignmentModel(
                $this->group([$one->requireId(), $claimed->requireId()]),
                $story->requireId(),
                [$one->requireId()],
                [$claimed->requireId()],
            ),
            TrendingWindow::Week,
        );

        self::assertEquals(
            [
                [$story->requireId(), $one->requireId(), '2031-05-14 10:00:00', 0, 1],
                [$story->requireId(), $claimed->requireId(), self::BUILT_AT, 0, 1],
                [$othersStory->requireId(), $claimed->requireId(), '2031-05-14 09:00:00', 0, 1],
            ],
            $this->members(),
        );
        self::assertContains(
            [$other->requireId(), $claimed->requireId(), $othersStory->requireId()],
            $this->placements(),
        );
    }

    public function testAnEntryKeepsItsEarliestJoinOnlyWithinItsUser(): void
    {
        [$entry] = $this->entries(1);
        $other = $this->fixtures->reader('other@example.com');
        $this->fixtures->storyOf($other, TrendingWindow::Week, '2031-05-14 09:00:00', $entry);

        $storyId = $this->saveNewFor($this->fixtures->owner(), $this->group([$entry->requireId()]));

        self::assertContains([$storyId, $entry->requireId(), self::BUILT_AT, 0, 1], $this->members());
    }

    public function testClearingOneUsersWindowLeavesAnotherUsersPlacementOfTheSameEntries(): void
    {
        [$one, $two] = $this->entryIds(2);
        $storyId = $this->saveNewFor($this->fixtures->owner(), $this->group([$one, $two]));
        $other = $this->fixtures->reader('other@example.com');
        $this->saveNewFor($other, $this->group([$one, $two]));

        $written = $this->history()->place(self::buildFor($other, TrendingWindow::Week), []);

        self::assertSame(2, $written);
        self::assertSame(
            [[$this->ownerId(), $one, $storyId], [$this->ownerId(), $two, $storyId]],
            $this->placements(),
        );
    }

    public function testAReaderWithNothingPlacedCountsNoOtherReadersPlacementAsChanged(): void
    {
        [$one, $two] = $this->entryIds(2);
        $this->saveNewFor($this->fixtures->owner(), $this->group([$one, $two]));
        $other = $this->fixtures->reader('other@example.com');

        self::assertSame(0, $this->history()->place(self::buildFor($other, TrendingWindow::Week), []));
    }

    public function testPruneLeavesOtherUsersStories(): void
    {
        $old = $this->fixtures->entry($this->fixtures->feed('prune'), 'Old', '2031-05-07 11:59:59');
        $other = $this->fixtures->reader('other@example.com');
        $this->fixtures->story(TrendingWindow::Week, '2031-05-08 00:00:00', $old);
        $othersStory = $this->fixtures->storyOf($other, TrendingWindow::Week, '2031-05-08 00:00:00', $old);

        $pruned = $this->history()->pruneUnfollowedOutside($this->build(TrendingWindow::Week));

        self::assertSame(1, $pruned);
        self::assertSame([$othersStory->requireId()], $this->storyIds());
    }

    private function history(): TrendingStoryHistoryRepository
    {
        return new TrendingStoryHistoryRepository($this->entityManager);
    }

    private function saveNew(TrendingWindow $window, TrendingStoryModel $group): int
    {
        return $this->saveAndPlace(new StoryAssignmentModel($group, null, []), $window);
    }

    /** As a build does: the group's entries are all the window places. */
    private function saveAndPlace(StoryAssignmentModel $assignment, TrendingWindow $window): int
    {
        $storyId = $this->save($assignment, $this->build($window));
        $this->history()->place($this->build($window), array_fill_keys($assignment->group->entryIds(), $storyId));

        return $storyId;
    }

    private function saveNewFor(User $user, TrendingStoryModel $group): int
    {
        $build = self::buildFor($user, TrendingWindow::Week);
        $storyId = $this->save(new StoryAssignmentModel($group, null, []), $build);
        $this->history()->place($build, array_fill_keys($group->entryIds(), $storyId));

        return $storyId;
    }

    private function save(StoryAssignmentModel $assignment, StoryBuildModel $build): int
    {
        return $this->history()->saveAll($build, [$assignment])[$assignment->group->entryIds()[0]];
    }

    private function build(TrendingWindow $window): StoryBuildModel
    {
        return self::buildFor($this->fixtures->owner(), $window);
    }

    private static function buildFor(User $user, TrendingWindow $window): StoryBuildModel
    {
        $builtAt = self::utc(self::BUILT_AT);

        return new StoryBuildModel($user->requireId(), $window, $window->startBefore($builtAt), $builtAt);
    }

    private function ownerId(): int
    {
        return $this->fixtures->owner()->requireId();
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

    private function clearPlacements(): void
    {
        $this->entityManager->getConnection()->executeStatement('DELETE FROM trending_story_placement');
    }

    private static function utc(string $moment): \DateTimeImmutable
    {
        return new \DateTimeImmutable($moment, new \DateTimeZone('UTC'));
    }

    /** @return list<array{int, int, string, int, int}> each member, its join, and 1 where the day and the week place it */
    private function members(): array
    {
        /** @var list<array{story_id: int|string, entry_id: int|string, joined_at: string}> $rows */
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT story_id, entry_id, joined_at FROM trending_story_entry ORDER BY story_id, entry_id',
        );
        $members = [];
        foreach ($rows as $row) {
            [$storyId, $entryId] = [(int) $row['story_id'], (int) $row['entry_id']];
            $members[] = [
                $storyId,
                $entryId,
                $row['joined_at'],
                $this->placedFlag(TrendingWindow::Day, $storyId, $entryId),
                $this->placedFlag(TrendingWindow::Week, $storyId, $entryId),
            ];
        }

        return $members;
    }

    private function placedFlag(TrendingWindow $window, int $storyId, int $entryId): int
    {
        return (int) \in_array($entryId, $this->placedEntries($window, $storyId), true);
    }

    /** @return list<int> */
    private function placedEntries(TrendingWindow $window, int $storyId): array
    {
        /** @var list<int|string> $entryIds */
        $entryIds = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT entry_id FROM trending_story_placement WHERE time_window = ? AND story_id = ? ORDER BY entry_id',
            [$window->value, $storyId],
        );

        return array_map(intval(...), $entryIds);
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

    /** @return list<array{int, int}> each story id with its owner's */
    private function storyOwners(): array
    {
        /** @var list<array{id: int|string, user_id: int|string}> $rows */
        $rows = $this->entityManager->getConnection()
            ->fetchAllAssociative('SELECT id, user_id FROM trending_story ORDER BY id');

        return array_map(static fn (array $row): array => [(int) $row['id'], (int) $row['user_id']], $rows);
    }

    /** @return list<array{int, int, int}> each placement's user, entry and story */
    private function placements(): array
    {
        /** @var list<array{user_id: int|string, entry_id: int|string, story_id: int|string}> $rows */
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT user_id, entry_id, story_id FROM trending_story_placement ORDER BY user_id, entry_id',
        );

        return array_map(
            static fn (array $row): array => [(int) $row['user_id'], (int) $row['entry_id'], (int) $row['story_id']],
            $rows,
        );
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
            ->fetchOne('SELECT COUNT(*) FROM trending_story_placement');
        self::assertIsNumeric($count);

        return (int) $count;
    }
}
