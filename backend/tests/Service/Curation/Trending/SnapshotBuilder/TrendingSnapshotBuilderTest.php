<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending\SnapshotBuilder;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\TrendingSnapshot;
use App\Entity\TrendingStory;
use App\Entity\TrendingStoryFollow;
use App\Enum\SocialNetwork;
use App\Enum\TrendingWindow;
use App\Repository\TrendingEntryRepository;
use App\Repository\TrendingPlacementSql;
use App\Repository\TrendingSnapshotRepository;
use App\Repository\TrendingStoryFollowRepository;
use App\Repository\TrendingStoryHistoryRepository;
use App\Repository\TrendingStoryRepository;
use App\Service\Curation\Trending\Model\FollowedStoryModel;
use App\Service\Curation\Trending\Model\FollowedStoryRowModel;
use App\Service\Curation\Trending\Model\RankedStoryModel;
use App\Service\Curation\Trending\Model\StoryAssignmentModel;
use App\Service\Curation\Trending\Model\StoryBuildModel;
use App\Service\Curation\Trending\Model\StoryMemberModel;
use App\Service\Curation\Trending\Model\TrendingStoryModel;
use App\Service\Curation\Trending\ReaderWindows;
use App\Service\Curation\Trending\TrendingStoryFollowing;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Curation\Trending\SnapshotBuilder\TrendingSnapshotBuilder;
use App\Tests\DbTestCase;
use App\Tests\Support\CountingLockStore;
use App\Tests\Support\SimulatedFailure;
use App\Tests\Support\TrendingFixtures;
use App\Tests\Support\TrendingWiring;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\Curation\Trending\Exception\TrendingBuildBusyException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Lock\Store\InMemoryStore;

final class TrendingSnapshotBuilderTest extends DbTestCase
{
    private const string NOW = '2031-05-14 12:00:00';

    private TrendingFixtures $fixtures;

    private MockClock $clock;

    private LockFactory $locks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtures = new TrendingFixtures($this->entityManager);
        $this->clock = new MockClock(self::NOW, 'UTC');
        $this->locks = new LockFactory(new InMemoryStore());
    }

    public function testABuildWhileAnotherHoldsTheLockWritesNothing(): void
    {
        $this->weekSizedHafenstreik();
        $running = $this->locks->createLock(TrendingSnapshotBuilder::LOCK_NAME . '-' . $this->ownerId());
        $running->acquire();

        try {
            $this->builder()->build($this->ownerId(), TrendingWindow::Day);
            self::fail('a build overlapping another must not run');
        } catch (TrendingBuildBusyException) {
        }

        self::assertSame([], $this->storyIds());
        self::assertSame(
            [],
            $this->entityManager->getConnection()->fetchFirstColumn('SELECT time_window FROM trending_snapshot'),
        );
    }

    public function testAWindowWithoutABuildPolicyFailsNamingIt(): void
    {
        $builder = new TrendingSnapshotBuilder(
            TrendingWiring::grouping($this->entityManager, $this->clock),
            new TrendingEntryRepository($this->entityManager),
            new ServiceLocator([]),
            new TrendingStoryHistoryRepository($this->entityManager),
            TrendingWiring::snapshots($this->entityManager),
            $this->entityManager,
            new NaiveUtcClock($this->clock),
            $this->locks,
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No story build policy is wired for the "week" window.');

        $builder->build($this->ownerId(), TrendingWindow::Week);
    }

    public function testABuildPutsOffItsLockBeforeWriting(): void
    {
        $this->weekSizedHafenstreik();
        $store = new CountingLockStore();
        $this->locks = new LockFactory($store);

        $this->builder()->build($this->ownerId(), TrendingWindow::Day);

        self::assertGreaterThan(CountingLockStore::ACQUIRING_PUT_OFFS, $store->putOffs);
    }

    public function testABuildThatLostItsLockWritesNothing(): void
    {
        $this->weekSizedHafenstreik();
        $this->locks = new LockFactory(new CountingLockStore(lockLost: true));

        try {
            $this->builder()->build($this->ownerId(), TrendingWindow::Day);
            self::fail('a build that lost its lock must not write');
        } catch (TrendingBuildBusyException $busy) {
            self::assertInstanceOf(LockConflictedException::class, $busy->getPrevious());
        }

        self::assertSame([], $this->storyIds());
        self::assertSame(
            [],
            $this->entityManager->getConnection()->fetchFirstColumn('SELECT time_window FROM trending_snapshot'),
        );
    }

    public function testEveryBuildReleasesTheLock(): void
    {
        $this->weekSizedHafenstreik();
        $this->locks = self::locksReleasedOnlyByTheirHolder();
        $this->builder()->build($this->ownerId(), TrendingWindow::Day);
        $this->builder()->build($this->ownerId(), TrendingWindow::Week);
        try {
            $this->builderWith($this->failingAfterTheTransactionBody())->build($this->ownerId(), TrendingWindow::Day);
        } catch (SimulatedFailure) {
        }

        $lockName = TrendingSnapshotBuilder::LOCK_NAME . '-' . $this->ownerId();
        self::assertTrue($this->locks->createLock($lockName)->acquire());
    }

    public function testABuildAfterOneThatThrewCanTakeTheLock(): void
    {
        $this->weekSizedHafenstreik();
        $this->locks = self::locksReleasedOnlyByTheirHolder();
        try {
            $this->builderWith($this->failingToStartTheTransaction())->build($this->ownerId(), TrendingWindow::Day);
            self::fail('the failure before the save must surface');
        } catch (SimulatedFailure) {
        }

        $report = $this->builder()->build($this->ownerId(), TrendingWindow::Day);

        self::assertSame(TrendingWindow::Day, $report->window);
    }

    public function testGroupsTheDaysHeadlinesIntoAStoryAndRecordsTheSnapshot(): void
    {
        $this->fixtures->hafenstreik($this->fixtures->feed('alpha'), 0, '2031-05-14 08:00:00');
        $this->fixtures->hafenstreik($this->fixtures->feed('beta'), 1, '2031-05-14 09:00:00');
        $this->fixtures->hafenstreik($this->fixtures->feed('gamma'), 2, '2031-05-14 10:00:00');
        $this->fixtures->entry($this->fixtures->feed('delta'), 'Wetter morgen sonnig', '2031-05-14 11:00:00');
        $this->fixtures->hafenstreik($this->fixtures->feed('epsilon'), 0, '2031-05-13 11:59:59');
        $this->fixtures->flush();

        $report = $this->builder()->build($this->ownerId(), TrendingWindow::Day);
        $this->entityManager->clear();

        self::assertSame(4, $report->entryCount, 'the entry a second before the window is not read');
        self::assertSame(1, $report->storyCount);
        self::assertSame(
            [['first_seen' => '2031-05-14 08:00:00', 'last_seen' => '2031-05-14 10:00:00']],
            $this->storedStories(TrendingWindow::Day),
        );
        self::assertSame(3, $this->memberCount());
        self::assertSame(
            [self::NOW],
            $this->entityManager->getConnection()->fetchFirstColumn(
                'SELECT joined_at FROM trending_story_entry UNION SELECT first_joined_at FROM trending_story'
                . ' UNION SELECT last_joined_at FROM trending_story',
            ),
        );
        $snapshot = $this->entityManager->find(
            TrendingSnapshot::class,
            ['user' => $this->ownerId(), 'window' => 'day'],
        );
        self::assertInstanceOf(TrendingSnapshot::class, $snapshot);
        self::assertSame(self::NOW, $snapshot->getBuiltAt()->format('Y-m-d H:i:s'));
        self::assertSame(4, $snapshot->getEntryCount());
        self::assertGreaterThan(0, $snapshot->getPeakMemoryBytes());
    }

    public function testKeepsATwoOutletGroup(): void
    {
        $this->fixtures->hafenstreik($this->fixtures->feed('alpha'), 0, '2031-05-14 08:00:00');
        $this->fixtures->hafenstreik($this->fixtures->feed('beta'), 1, '2031-05-14 09:00:00');
        $this->fixtures->flush();

        self::assertSame(1, $this->builder()->build($this->ownerId(), TrendingWindow::Day)->storyCount);
        self::assertSame(2, $this->memberCount());
    }

    public function testBuildsOnlyTheUsersStories(): void
    {
        $reader = $this->fixtures->reader('other-reader@example.com');
        [$alpha, $beta, $gamma] = array_map($this->fixtures->feed(...), ['alpha', 'beta', 'gamma']);
        foreach ([$alpha, $beta, $gamma] as $variant => $feed) {
            $this->fixtures->hafenstreik($feed, $variant, \sprintf('2031-05-14 %02d:00:00', 8 + $variant));
        }
        $unrelated = $this->fixtures->feedNobodyFollows('unrelated');
        $this->fixtures->entry($unrelated, 'Wetter morgen sonnig', '2031-05-14 11:00:00');
        $this->fixtures->subscribe($reader, $alpha, $unrelated);
        $this->fixtures->flush();

        $this->builder()->build($reader->requireId(), TrendingWindow::Day);
        self::assertSame([], $this->storyIds(), 'one outlet is no story');

        $this->builder()->build($this->ownerId(), TrendingWindow::Day);

        self::assertSame(
            [$this->ownerId()],
            $this->intColumn('SELECT user_id FROM trending_story'),
        );
        self::assertSame(
            [$this->ownerId()],
            $this->intColumn('SELECT user_id FROM trending_story_placement GROUP BY user_id'),
        );
        self::assertSame(
            [$reader->requireId(), $this->ownerId()],
            $this->sortedIntColumn('SELECT user_id FROM trending_snapshot'),
        );
        self::assertSame(
            [$reader->requireId() => 2, $this->ownerId() => 3],
            $this->activeFeedCountsByUser(),
        );
    }

    public function testAnotherUsersRunningBuildDoesNotBlock(): void
    {
        $this->weekSizedHafenstreik();
        $reader = $this->fixtures->reader('busy-reader@example.com');
        $running = $this->locks->createLock('trending-build-' . $reader->requireId());
        self::assertTrue($running->acquire());

        $report = $this->builder()->build($this->ownerId(), TrendingWindow::Day);

        self::assertSame($this->ownerId(), $report->userId);
        $this->expectException(TrendingBuildBusyException::class);
        $this->builder()->build($reader->requireId(), TrendingWindow::Day);
    }

    public function testTheReportCountsTheUsersActiveFeeds(): void
    {
        $this->weekSizedHafenstreik();

        $report = $this->builder()->build($this->ownerId(), TrendingWindow::Day);

        self::assertSame(5, $report->activeFeedCount);
        self::assertSame(
            [5],
            $this->intColumn('SELECT active_feed_count FROM trending_snapshot'),
        );
    }

    public function testTheActiveFeedCountIsPerWindow(): void
    {
        $this->fixtures->entry($this->fixtures->feed('today'), 'Wetter morgen sonnig', '2031-05-14 08:00:00');
        $this->fixtures->entry($this->fixtures->feed('yesterday'), 'Fahrplan ab Montag', '2031-05-13 11:00:00');
        $this->fixtures->flush();

        $day = $this->builder()->build($this->ownerId(), TrendingWindow::Day);
        $week = $this->builder()->build($this->ownerId(), TrendingWindow::Week);

        self::assertSame(1, $day->activeFeedCount);
        self::assertSame(2, $week->activeFeedCount);
        self::assertSame(
            ['day' => 1, 'week' => 2],
            $this->entityManager->getConnection()->fetchAllKeyValue(
                'SELECT time_window, active_feed_count FROM trending_snapshot ORDER BY time_window',
            ),
        );
    }

    public function testTwoUsersStoriesNeverMerge(): void
    {
        $reader = $this->fixtures->reader('bridged-reader@example.com');
        [$entries, $feeds] = $this->hafenstreikEntries();
        $this->fixtures->subscribe($reader, $feeds[0], $feeds[1], $feeds[2]);
        $this->fixtures->flush();
        $owner = $this->fixtures->owner();
        $this->fixtures->storyOf(
            $owner,
            TrendingWindow::Week,
            '2031-05-14 11:00:00',
            $entries[0],
            $entries[1],
            $entries[2],
        );
        $this->fixtures->storyOf(
            $owner,
            TrendingWindow::Week,
            '2031-05-14 11:00:00',
            $entries[3],
            $entries[4],
        );
        $readersStory = $this->fixtures->storyOf(
            $reader,
            TrendingWindow::Week,
            '2031-05-14 11:00:00',
            $entries[0],
            $entries[1],
            $entries[2],
        );
        $readersRows = $this->rowsOfUser($reader->requireId());

        $this->builder()->build($owner->requireId(), TrendingWindow::Week);

        self::assertSame($readersRows, $this->rowsOfUser($reader->requireId()));
        self::assertSame(
            1,
            $this->intColumn('SELECT COUNT(*) FROM trending_story WHERE user_id = ?', [$owner->requireId()])[0],
            'the owner\'s two stories bridged into one',
        );
        $ownersRows = $this->rowsOfUser($owner->requireId());

        $this->builder()->build($reader->requireId(), TrendingWindow::Week);

        self::assertSame($ownersRows, $this->rowsOfUser($owner->requireId()));
        self::assertSame(
            1,
            $this->intColumn('SELECT COUNT(*) FROM trending_story WHERE user_id = ?', [$reader->requireId()])[0],
        );
        self::assertContains($readersStory->requireId(), $this->storyIds());
    }

    public function testPostsSharingAnArticleJoinItsStoryThroughTheirLinkCards(): void
    {
        $this->fixtures->article(
            $this->fixtures->feed('news'),
            'Etwas ganz anderes',
            '2031-05-14 08:00:00',
            'https://news.example.com/streik?utm_source=feed',
        );
        foreach (['ann', 'ben', 'cat'] as $index => $handle) {
            $this->fixtures->postSharing(
                $this->fixtures->feed($handle, SocialNetwork::Bluesky),
                ['Lesenswert', 'Wichtig!', 'Seht euch das an'][$index],
                \sprintf('2031-05-14 %02d:00:00', 9 + $index),
                'https://news.example.com/streik',
            );
        }
        $this->fixtures->flush();

        $report = $this->builder()->build($this->ownerId(), TrendingWindow::Day);

        self::assertSame(1, $report->storyCount);
        self::assertSame(4, $this->memberCount());
        self::assertSame(
            ['news.example.com'],
            $this->entityManager->getConnection()
                ->fetchFirstColumn('SELECT DISTINCT article_site FROM trending_story_entry'),
        );
    }

    public function testRebuildingTheDayLeavesTheWeekAlone(): void
    {
        foreach (['alpha', 'beta', 'gamma', 'delta', 'omega'] as $variant => $name) {
            $this->fixtures->hafenstreik($this->fixtures->feed($name), $variant, '2031-05-14 10:00:00');
        }
        $this->fixtures->flush();
        $this->builder()->build($this->ownerId(), TrendingWindow::Week);

        $this->builder()->build($this->ownerId(), TrendingWindow::Day);

        self::assertCount(1, $this->storedStories(TrendingWindow::Week));
        self::assertCount(1, $this->storedStories(TrendingWindow::Day));
    }

    public function testAFailedSaveKeepsTheOldStories(): void
    {
        $alpha = $this->fixtures->hafenstreik($this->fixtures->feed('alpha'), 0, '2031-05-14 08:00:00');
        $beta = $this->fixtures->hafenstreik($this->fixtures->feed('beta'), 1, '2031-05-14 09:00:00');
        $this->fixtures->story(TrendingWindow::Day, '2031-05-14 11:00:00', $alpha, $beta);
        $this->entityManager->persist(
            new TrendingSnapshot(
                $this->fixtures->owner(),
                TrendingWindow::Day,
                new \DateTimeImmutable('2031-05-14 11:00:00'),
                1,
                1,
                1,
                0,
                0,
            ),
        );
        $this->fixtures->hafenstreik($this->fixtures->feed('gamma'), 2, '2031-05-14 10:00:00');
        $this->fixtures->flush();
        $before = $this->history();

        try {
            $this->builderWith($this->failingAfterTheTransactionBody())->build($this->ownerId(), TrendingWindow::Day);
            self::fail('the failure after the save must surface');
        } catch (SimulatedFailure) {
        }

        self::assertSame($before, $this->history());
        self::assertSame(
            ['2031-05-14 11:00:00'],
            $this->entityManager->getConnection()
                ->fetchFirstColumn('SELECT built_at FROM trending_snapshot WHERE time_window = \'day\''),
        );
    }

    public function testARebuildOverUnchangedInputChangesNothing(): void
    {
        $this->weekSizedHafenstreik();
        $this->builder()->build($this->ownerId(), TrendingWindow::Week);
        $history = $this->history();
        $stories = $this->storyRows();

        $report = $this->builder()->build($this->ownerId(), TrendingWindow::Week);

        self::assertSame($history, $this->history());
        self::assertSame($stories, $this->storyRows());
        self::assertSame(
            ['kept' => 1, 'created' => 0, 'merged' => 0, 'split' => 0, 'pruned' => 0],
            array_intersect_key($report->toLogContext(), array_flip(['kept', 'created', 'merged', 'split', 'pruned'])),
        );
    }

    public function testTheDayAndTheWeekPlaceOneStory(): void
    {
        $feeds = $this->weekSizedHafenstreik();
        $reader = $this->fixtures->reader('d41@example.com');
        $this->fixtures->subscribe($reader, ...$feeds);
        $this->fixtures->flush();

        $this->builder()->build($this->ownerId(), TrendingWindow::Day);
        $this->builder()->build($this->ownerId(), TrendingWindow::Week);

        $storyIds = $this->storyIds();
        self::assertCount(1, $storyIds);
        self::assertSame([[1, 1]], $this->flagsOfEveryMember());
        self::assertSame(
            $storyIds,
            RankedStoryModel::storyIdsOf(
                $this->readerWindows()->shownStories($reader->requireId(), TrendingWindow::Day),
            ),
        );
        self::assertSame([], $this->readerWindows()->shownStories($reader->requireId(), TrendingWindow::Week));
    }

    public function testANewArticleJoinsTheStoryWithTheBuildTime(): void
    {
        $this->weekSizedHafenstreik();
        $this->builder()->build($this->ownerId(), TrendingWindow::Week);
        $storyIds = $this->storyIds();
        $late = $this->fixtures->hafenstreik($this->fixtures->feed('late'), 0, '2031-05-14 12:30:00');
        $this->fixtures->flush();
        $this->clock->modify('+1 hour');

        $this->builder()->build($this->ownerId(), TrendingWindow::Week);

        self::assertSame($storyIds, $this->storyIds());
        $joinedAt = $this->joinedAtByEntry();
        self::assertSame('2031-05-14 13:00:00', $joinedAt[$late->requireId()]);
        unset($joinedAt[$late->requireId()]);
        self::assertSame(array_fill_keys(array_keys($joinedAt), self::NOW), $joinedAt);
        self::assertSame(
            ['2031-05-14 12:00:00', '2031-05-14 13:00:00'],
            array_values($this->entityManager->getConnection()
                ->fetchAssociative('SELECT first_joined_at, last_joined_at FROM trending_story') ?: []),
        );
    }

    public function testAMemberThatLeavesTheWindowStaysInTheHistory(): void
    {
        $leaving = $this->fixtures->hafenstreik($this->fixtures->feed('alpha'), 0, '2031-05-13 13:00:00');
        $this->fixtures->hafenstreik($this->fixtures->feed('beta'), 1, '2031-05-14 08:00:00');
        $this->fixtures->hafenstreik($this->fixtures->feed('gamma'), 2, '2031-05-14 09:00:00');
        $this->fixtures->hafenstreik($this->fixtures->feed('delta'), 3, '2031-05-14 10:00:00');
        $this->fixtures->flush();
        $this->builder()->build($this->ownerId(), TrendingWindow::Day);
        $storyIds = $this->storyIds();
        $this->clock->modify('+2 hours');

        $this->builder()->build($this->ownerId(), TrendingWindow::Day);

        self::assertSame($storyIds, $this->storyIds());
        self::assertSame(4, $this->memberCount());
        self::assertSame(
            ['in_day' => 0, 'joined_at' => self::NOW],
            $this->flagsOf($leaving),
        );
    }

    public function testTwoStoriesOneWeekGroupBridgesAreMergedIntoTheLarger(): void
    {
        [$entries] = $this->hafenstreikEntries();
        $smaller = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 11:00:00', $entries[3], $entries[4]);
        $larger = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 11:00:00', ...\array_slice($entries, 0, 3));
        $reader = $this->fixtures->reader('merge@example.com');
        $this->fixtures->follow($reader, $smaller, '2031-05-14 11:00:00');

        $report = $this->builder()->build($this->ownerId(), TrendingWindow::Week);

        self::assertSame([$larger->requireId()], $this->storyIds());
        self::assertSame(5, $this->memberCount());
        self::assertSame(1, $report->match->merged());
        self::assertSame(
            [$larger->requireId()],
            $this->intColumn('SELECT story_id FROM trending_story_follow'),
        );
    }

    public function testTheDayBuildNeverMerges(): void
    {
        [$entries] = $this->hafenstreikEntries();
        $smaller = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 11:00:00', $entries[3], $entries[4]);
        $larger = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 11:00:00', ...\array_slice($entries, 0, 3));

        $report = $this->builder()->build($this->ownerId(), TrendingWindow::Day);

        self::assertSame([$smaller->requireId(), $larger->requireId()], $this->storyIds());
        self::assertSame(0, $report->match->merged());
        self::assertSame(
            [$larger->requireId()],
            $this->intColumn("SELECT DISTINCT story_id FROM trending_story_placement WHERE time_window = 'day'"),
        );
    }

    public function testASplitKeepsTheIdAndTheFollowOnTheLargerPart(): void
    {
        [$strike] = $this->hafenstreikEntries();
        $volcano = $this->volcanoEntries();
        $story = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 11:00:00', ...$strike, ...$volcano);
        $reader = $this->fixtures->reader('split@example.com');
        $this->fixtures->follow($reader, $story, '2031-05-14 11:00:00');

        $report = $this->builder()->build($this->ownerId(), TrendingWindow::Week);

        self::assertSame(
            ['kept' => 1, 'created' => 1, 'split' => 1],
            [
                'kept' => $report->match->kept(),
                'created' => $report->match->created(),
                'split' => $report->match->split,
            ],
        );
        self::assertSame(
            array_map(static fn (Entry $entry): int => $entry->requireId(), $volcano),
            $this->weekEntriesOf($story->requireId()),
        );
        self::assertSame(
            [$story->requireId()],
            $this->intColumn('SELECT story_id FROM trending_story_follow'),
        );
    }

    public function testTheNextDayBuildKeepsTheSplitOffGroupOnItsNewStory(): void
    {
        [$strike] = $this->hafenstreikEntries();
        $volcano = $this->volcanoEntries();
        $story = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 11:00:00', ...$strike, ...$volcano);
        $this->builder()->build($this->ownerId(), TrendingWindow::Week);
        [, $newStoryId] = $this->storyIds();

        $this->builder()->build($this->ownerId(), TrendingWindow::Day);

        $strikeIds = array_map(static fn (Entry $entry): int => $entry->requireId(), $strike);
        self::assertSame(
            $strikeIds,
            $this->intColumn(
                "SELECT entry_id FROM trending_story_placement WHERE story_id = ? AND time_window = 'day'"
                . ' ORDER BY entry_id',
                [$newStoryId],
            ),
        );
        self::assertSame(
            array_map(static fn (Entry $entry): int => $entry->requireId(), $volcano),
            $this->intColumn(
                'SELECT entry_id FROM trending_story_entry WHERE story_id = ? ORDER BY entry_id',
                [$story->requireId()],
            ),
        );
    }

    public function testTwoDayGroupsOverOneStoryBothAttachToIt(): void
    {
        [$strike] = $this->hafenstreikEntries();
        $volcano = $this->volcanoEntries();
        $story = $this->fixtures->story(
            TrendingWindow::Week,
            '2031-05-14 11:00:00',
            ...\array_slice($strike, 0, 4),
            ...\array_slice($volcano, 0, 5),
        );

        $report = $this->builder()->build($this->ownerId(), TrendingWindow::Day);

        self::assertSame([$story->requireId()], $this->storyIds());
        self::assertSame(
            ['kept' => 2, 'created' => 0, 'merged' => 0, 'split' => 0],
            $report->match->toLogContext(),
        );
        self::assertSame([[1, 1], [1, 0]], $this->flagsOfEveryMember());
        $joinedAt = $this->joinedAtByEntry();
        self::assertSame(self::NOW, $joinedAt[$strike[4]->requireId()]);
        self::assertSame(self::NOW, $joinedAt[$volcano[5]->requireId()]);
        unset($joinedAt[$strike[4]->requireId()], $joinedAt[$volcano[5]->requireId()]);
        self::assertSame(array_fill_keys(array_keys($joinedAt), '2031-05-14 11:00:00'), $joinedAt);
    }

    public function testAnEntryTheWeekClaimsAndTheDayReattachesIsNotNewAgain(): void
    {
        [$strike, $feeds] = $this->hafenstreikEntries();
        $reader = $this->fixtures->reader('regrouped@example.com');
        $this->fixtures->subscribe($reader, ...$feeds);
        $this->fixtures->flush();
        $this->builder()->build($this->ownerId(), TrendingWindow::Day);
        [$dayStoryId] = $this->storyIds();
        $dayStory = $this->entityManager->find(TrendingStory::class, $dayStoryId);
        self::assertInstanceOf(TrendingStory::class, $dayStory);
        $this->fixtures->follow($reader, $dayStory, self::NOW);
        $this->clock->modify('+1 hour');
        $this->weekClaims($strike[0], ...$this->volcanoEntries());
        self::assertNotContains($strike[0]->requireId(), $this->memberIdsOf($dayStoryId));
        $this->clock->modify('+1 hour');

        $this->builder()->build($this->ownerId(), TrendingWindow::Day);

        self::assertContains($strike[0]->requireId(), $this->memberIdsOf($dayStoryId));
        self::assertSame(
            [self::NOW],
            $this->entityManager->getConnection()->fetchFirstColumn(
                'SELECT joined_at FROM trending_story_entry WHERE story_id = ? AND entry_id = ?',
                [$dayStoryId, $strike[0]->requireId()],
            ),
        );
        self::assertSame([[0, self::NOW]], array_map(
            static fn (FollowedStoryRowModel $row): array => [
                $row->newCount,
                $row->lastJoinedAt->format('Y-m-d H:i:s'),
            ],
            $this->follows()->followedBy($reader->requireId()),
        ));
        $this->clock->modify('2031-05-16 13:00:00');
        self::assertSame([true], array_map(
            static fn (FollowedStoryModel $story): bool => $story->quiet,
            $this->following()->followedBy($reader->requireId())->stories,
        ));
    }

    public function testSeedStoriesOfBothWindowsConvergeOnTheFirstWeekBuild(): void
    {
        [$entries] = $this->hafenstreikEntries();
        $day = $this->fixtures->story(TrendingWindow::Day, '2031-05-14 11:00:00', ...$entries);
        $this->fixtures->story(TrendingWindow::Week, '2031-05-14 11:00:00', ...$entries);

        $this->builder()->build($this->ownerId(), TrendingWindow::Week);

        self::assertSame([$day->requireId()], $this->storyIds());
        self::assertSame([[1, 1]], $this->flagsOfEveryMember());
    }

    public function testAnEntryOnTheWindowStartIsNeverInsertedTwice(): void
    {
        $onTheStart = $this->fixtures->hafenstreik($this->fixtures->feed('alpha'), 0, '2031-05-07 12:00:00');
        foreach (['beta', 'gamma', 'delta', 'omega'] as $variant => $name) {
            $this->fixtures->hafenstreik($this->fixtures->feed($name), $variant + 1, '2031-05-08 08:00:00');
        }
        $this->fixtures->story(TrendingWindow::Week, '2031-05-07 13:00:00', $onTheStart);

        $report = $this->builder()->build($this->ownerId(), TrendingWindow::Week);

        self::assertSame(1, $report->match->kept());
        self::assertSame('2031-05-07 13:00:00', $this->joinedAtByEntry()[$onTheStart->requireId()]);
    }

    public function testAGroupEntryOnTheWindowStartIsStillClaimed(): void
    {
        $onTheStart = $this->fixtures->hafenstreik($this->fixtures->feed('alpha'), 0, '2031-05-07 12:00:00');
        $others = [];
        foreach (['beta', 'gamma', 'delta', 'omega'] as $variant => $name) {
            $others[] = $this->fixtures->hafenstreik($this->fixtures->feed($name), $variant + 1, '2031-05-08 08:00:00');
        }
        $unrelatedFeed = $this->fixtures->feed('unrelated');
        $story = $this->fixtures->story(TrendingWindow::Week, '2031-05-08 09:00:00', ...$others);
        $holder = $this->fixtures->story(
            TrendingWindow::Week,
            '2031-05-07 13:00:00',
            $onTheStart,
            $this->fixtures->entry($unrelatedFeed, 'Gartenschau eröffnet', '2031-05-09 08:00:00'),
            $this->fixtures->entry($unrelatedFeed, 'Neue Brücke freigegeben', '2031-05-10 08:00:00'),
        );

        $this->builder()->build($this->ownerId(), TrendingWindow::Week);

        self::assertSame(
            [$story->requireId()],
            $this->intColumn(
                'SELECT story_id FROM trending_story_entry WHERE entry_id = ?',
                [$onTheStart->requireId()],
            ),
        );
        self::assertContains($holder->requireId(), $this->storyIds());
    }

    public function testFollowsAndHidesSurviveARebuild(): void
    {
        [$entries] = $this->hafenstreikEntries();
        $story = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 11:00:00', ...$entries);
        $reader = $this->fixtures->reader('keeps@example.com');
        $this->fixtures->follow($reader, $story, '2031-05-14 11:00:00');
        $this->fixtures->hide($reader, $story);

        $this->builder()->build($this->ownerId(), TrendingWindow::Week);
        $this->builder()->build($this->ownerId(), TrendingWindow::Day);

        self::assertSame([$story->requireId()], $this->storyIds());
        self::assertSame(
            [$story->requireId()],
            $this->intColumn('SELECT story_id FROM trending_story_follow'),
        );
        self::assertSame(
            [$story->requireId()],
            $this->intColumn('SELECT story_id FROM trending_story_hide'),
        );
    }

    public function testAnUnfollowedStoryOutsideTheWeekIsPrunedAndAFollowedOneStays(): void
    {
        $old = $this->fixtures->hafenstreik($this->fixtures->feed('old'), 0, '2031-05-01 08:00:00');
        $unfollowed = $this->fixtures->story(TrendingWindow::Week, '2031-05-01 09:00:00', $old);
        $followed = $this->fixtures->story(TrendingWindow::Week, '2031-05-01 09:00:00', $old);
        $this->fixtures->follow($this->fixtures->reader('follows@example.com'), $followed, '2031-05-01 09:00:00');

        $dayReport = $this->builder()->build($this->ownerId(), TrendingWindow::Day);

        self::assertSame(0, $dayReport->pruned);
        self::assertSame([$unfollowed->requireId(), $followed->requireId()], $this->storyIds());

        $weekReport = $this->builder()->build($this->ownerId(), TrendingWindow::Week);

        self::assertSame(1, $weekReport->pruned);
        self::assertSame([$followed->requireId()], $this->storyIds());
    }

    private function failingToStartTheTransaction(): EntityManagerInterface
    {
        return new class ($this->entityManager) extends EntityManagerDecorator {
            public function wrapInTransaction(callable $func): never
            {
                throw new SimulatedFailure('Simulated failure before the replacement.');
            }
        };
    }

    private function failingAfterTheTransactionBody(): EntityManagerInterface
    {
        return new class ($this->entityManager) extends EntityManagerDecorator {
            public function wrapInTransaction(callable $func): mixed
            {
                return parent::wrapInTransaction(
                    static function (EntityManagerInterface $entityManager) use ($func): never {
                        $func($entityManager);

                        throw new SimulatedFailure('Simulated failure after the replacement.');
                    },
                );
            }
        };
    }

    /** A lock left behind would otherwise free itself when the builder drops it, hiding a missing release. */
    private static function locksReleasedOnlyByTheirHolder(): LockFactory
    {
        return new class (new InMemoryStore()) extends LockFactory {
            public function createLock(
                string $resource,
                ?float $ttl = 300.0,
                bool $autoRelease = true,
            ): SharedLockInterface {
                return parent::createLock($resource, $ttl, false);
            }
        };
    }

    /** @return array{list<array<string, mixed>>, list<array<string, mixed>>, list<array<string, mixed>>} */
    private function rowsOfUser(int $userId): array
    {
        $connection = $this->entityManager->getConnection();

        return [
            $connection->fetchAllAssociative('SELECT * FROM trending_story WHERE user_id = ? ORDER BY id', [$userId]),
            $connection->fetchAllAssociative(
                'SELECT member.* FROM trending_story_entry member'
                . ' JOIN trending_story story ON story.id = member.story_id WHERE story.user_id = ?'
                . ' ORDER BY member.story_id, member.entry_id',
                [$userId],
            ),
            $connection->fetchAllAssociative(
                'SELECT * FROM trending_story_placement WHERE user_id = ? ORDER BY time_window, entry_id',
                [$userId],
            ),
        ];
    }

    /** @return array<int, int> */
    private function activeFeedCountsByUser(): array
    {
        $counts = [];
        foreach (
            $this->entityManager->getConnection()
                ->fetchAllKeyValue('SELECT user_id, active_feed_count FROM trending_snapshot') as $userId => $count
        ) {
            $counts[(int) $userId] = (int) $count;
        }
        ksort($counts);

        return $counts;
    }

    /** @return list<int> */
    private function sortedIntColumn(string $sql): array
    {
        $values = $this->intColumn($sql);
        sort($values);

        return $values;
    }

    private function ownerId(): int
    {
        return $this->fixtures->owner()->requireId();
    }

    private function builder(): TrendingSnapshotBuilder
    {
        return $this->builderWith($this->entityManager);
    }

    private function builderWith(EntityManagerInterface $entityManager): TrendingSnapshotBuilder
    {
        return new TrendingSnapshotBuilder(
            TrendingWiring::grouping($this->entityManager, $this->clock),
            new TrendingEntryRepository($this->entityManager),
            TrendingWiring::policies($this->entityManager),
            new TrendingStoryHistoryRepository($this->entityManager),
            TrendingWiring::snapshots($this->entityManager),
            $entityManager,
            new NaiveUtcClock($this->clock),
            $this->locks,
        );
    }

    /** A week save whose group, the week story of $weekMembers, takes $claimed from the story holding it. */
    private function weekClaims(Entry $claimed, Entry ...$weekMembers): void
    {
        $weekStory = $this->fixtures->story(TrendingWindow::Week, '2031-05-14 11:00:00', ...$weekMembers);
        $weekMembers = array_values($weekMembers);
        $entries = [$claimed, ...$weekMembers];
        $builtAt = new \DateTimeImmutable($this->clock->now()->format('Y-m-d H:i:s'), new \DateTimeZone('UTC'));
        $history = new TrendingStoryHistoryRepository($this->entityManager);
        $build = new StoryBuildModel(
            $this->fixtures->owner()->requireId(),
            TrendingWindow::Week,
            TrendingWindow::Week->startBefore($builtAt),
            $builtAt,
        );
        $storyIdByEntry = $history->saveAll(
            $build,
            [new StoryAssignmentModel(
                new TrendingStoryModel(
                    array_map(
                        static fn (Entry $entry): StoryMemberModel => new StoryMemberModel(
                            $entry->requireId(),
                            $entry->getFeed()->requireId(),
                            null,
                        ),
                        $entries,
                    ),
                    $claimed->getEffectiveDate(),
                    $builtAt,
                ),
                $weekStory->requireId(),
                array_map(static fn (Entry $entry): int => $entry->requireId(), $weekMembers),
                [$claimed->requireId()],
            )],
        );
        $history->place($build, $storyIdByEntry);
    }

    private function following(): TrendingStoryFollowing
    {
        $stories = $this->entityManager->getRepository(TrendingStory::class);
        self::assertInstanceOf(TrendingStoryRepository::class, $stories);
        $snapshots = $this->entityManager->getRepository(TrendingSnapshot::class);
        self::assertInstanceOf(TrendingSnapshotRepository::class, $snapshots);

        return new TrendingStoryFollowing($stories, $this->follows(), $snapshots, new NaiveUtcClock($this->clock));
    }

    private function follows(): TrendingStoryFollowRepository
    {
        $follows = $this->entityManager->getRepository(TrendingStoryFollow::class);
        self::assertInstanceOf(TrendingStoryFollowRepository::class, $follows);

        return $follows;
    }

    /** @return list<int> */
    private function memberIdsOf(int $storyId): array
    {
        return $this->intColumn(
            'SELECT entry_id FROM trending_story_entry WHERE story_id = ? ORDER BY entry_id',
            [$storyId],
        );
    }

    private function readerWindows(): ReaderWindows
    {
        $stories = $this->entityManager->getRepository(TrendingStory::class);
        self::assertInstanceOf(TrendingStoryRepository::class, $stories);

        return new ReaderWindows(
            new TrendingEntryRepository($this->entityManager),
            $stories,
            new NaiveUtcClock($this->clock),
        );
    }

    /** @return list<Feed> five feeds, one variant each, inside the day */
    private function weekSizedHafenstreik(): array
    {
        [, $feeds] = $this->hafenstreikEntries();

        return $feeds;
    }

    /** @return array{list<Entry>, list<Feed>} */
    private function hafenstreikEntries(): array
    {
        $entries = [];
        $feeds = [];
        foreach (['alpha', 'beta', 'gamma', 'delta', 'omega'] as $variant => $name) {
            $feeds[] = $feed = $this->fixtures->feed($name);
            $entries[] = $this->fixtures->hafenstreik($feed, $variant, \sprintf('2031-05-14 %02d:00:00', 6 + $variant));
        }
        $this->fixtures->flush();

        return [$entries, $feeds];
    }

    /** @return list<Entry> an article and the five posts sharing it, a story of six feeds */
    private function volcanoEntries(): array
    {
        $url = 'https://news.example.com/vulkan';
        $entries = [
            $this->fixtures->article($this->fixtures->feed('news'), 'Etwas ganz anderes', '2031-05-14 08:00:00', $url),
        ];
        foreach (['ann', 'ben', 'cat', 'dan', 'eve'] as $index => $handle) {
            $entries[] = $this->fixtures->postSharing(
                $this->fixtures->feed($handle, SocialNetwork::Bluesky),
                'Seht euch das an ' . $index,
                \sprintf('2031-05-14 %02d:00:00', 9 + $index),
                $url,
            );
        }
        $this->fixtures->flush();

        return $entries;
    }

    /** @return list<array<string, mixed>> every member with its story, join time and placement */
    private function history(): array
    {
        return $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT member.story_id, member.entry_id, member.joined_at, ' . self::placedFlag(TrendingWindow::Day)
            . ' AS in_day, ' . self::placedFlag(TrendingWindow::Week) . ' AS in_week FROM trending_story_entry member'
            . ' ORDER BY member.story_id, member.entry_id',
        );
    }

    private static function placedFlag(TrendingWindow $window): string
    {
        return 'CASE WHEN ' . TrendingPlacementSql::placedIn($window, 'member') . ' THEN 1 ELSE 0 END';
    }

    /** @return list<array<string, mixed>> */
    private function storyRows(): array
    {
        return $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT id, first_seen, last_seen, first_joined_at, last_joined_at FROM trending_story ORDER BY id',
        );
    }

    /** @return list<int> */
    private function storyIds(): array
    {
        return $this->intColumn('SELECT id FROM trending_story ORDER BY id');
    }

    /** @return list<array{int, int}> the distinct (in_day, in_week) pairs */
    private function flagsOfEveryMember(): array
    {
        return array_map(
            static fn (int $flags): array => [intdiv($flags, 2), $flags % 2],
            $this->intColumn(
                'SELECT DISTINCT ' . self::placedFlag(TrendingWindow::Day) . ' * 2 + '
                . self::placedFlag(TrendingWindow::Week) . ' FROM trending_story_entry member',
            ),
        );
    }

    /** @return array<string, mixed> */
    private function flagsOf(Entry $entry): array
    {
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT ' . self::placedFlag(TrendingWindow::Day) . ' AS in_day, member.joined_at'
            . ' FROM trending_story_entry member WHERE member.entry_id = ?',
            [$entry->requireId()],
        );
        self::assertIsArray($row);

        self::assertIsNumeric($row['in_day']);

        return ['in_day' => (int) $row['in_day'], 'joined_at' => $row['joined_at']];
    }

    /** @return array<int, mixed> */
    private function joinedAtByEntry(): array
    {
        $rows = $this->entityManager->getConnection()
            ->fetchAllKeyValue('SELECT entry_id, joined_at FROM trending_story_entry ORDER BY entry_id');

        return array_combine(array_map(intval(...), array_keys($rows)), array_values($rows));
    }

    /** @return list<int> */
    private function weekEntriesOf(int $storyId): array
    {
        return $this->intColumn(
            'SELECT member.entry_id FROM trending_story_entry member WHERE member.story_id = ? AND '
            . TrendingPlacementSql::placedIn(TrendingWindow::Week, 'member') . ' ORDER BY member.entry_id',
            [$storyId],
        );
    }

    /** @return list<array<string, mixed>> */
    private function storedStories(TrendingWindow $window): array
    {
        return $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT story.first_seen, story.last_seen FROM trending_story story WHERE EXISTS (SELECT 1'
            . ' FROM trending_story_entry member WHERE member.story_id = story.id AND '
            . TrendingPlacementSql::placedIn($window, 'member') . ')',
        );
    }

    private function memberCount(): int
    {
        $count = $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM trending_story_entry');
        self::assertIsNumeric($count);

        return (int) $count;
    }

    /**
     * @param list<int> $parameters
     *
     * @return list<int>
     */
    private function intColumn(string $sql, array $parameters = []): array
    {
        $values = [];
        foreach ($this->entityManager->getConnection()->fetchFirstColumn($sql, $parameters) as $value) {
            self::assertIsNumeric($value);
            $values[] = (int) $value;
        }

        return $values;
    }
}
