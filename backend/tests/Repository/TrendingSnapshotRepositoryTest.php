<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\TrendingSnapshot;
use App\Enum\TrendingWindow;
use App\Repository\TrendingPlacementSql;
use App\Repository\TrendingSnapshotRepository;
use App\Service\Curation\Trending\Model\StoryMemberModel;
use App\Service\Curation\Trending\Model\TrendingBuildReportModel;
use App\Service\Curation\Trending\Model\TrendingStoryModel;
use App\Tests\DbTestCase;
use App\Tests\Support\TrendingFixtures;
use App\Entity\LinkCardContent;
use App\Tests\Support\SharedLinks;

final class TrendingSnapshotRepositoryTest extends DbTestCase
{
    public function testAMemberRecordsItsLink(): void
    {
        [$one, $two] = $this->twoEntries();
        $link = SharedLinks::of(
            $this->entityManager,
            new LinkCardContent('https://outlet.example/storm', 'Storm', null, null, 'Outlet'),
        );
        $story = new TrendingStoryModel(
            [
                new StoryMemberModel($one, 101, 'outlet.example', $link->requireId()),
                new StoryMemberModel($two, 101, null),
            ],
            self::utc('2031-05-14 08:00:00'),
            self::utc('2031-05-14 09:00:00'),
        );

        $this->replace(self::report(TrendingWindow::Day, 1), [$story]);

        self::assertEquals(
            [$one => $link->requireId(), $two => null],
            $this->linksByEntry(),
        );
    }

    public function testDeletingALinkLeavesItsMemberWithoutOne(): void
    {
        [$one, $two] = $this->twoEntries();
        $link = SharedLinks::of(
            $this->entityManager,
            new LinkCardContent('https://outlet.example/storm', 'Storm', null, null, 'Outlet'),
        );
        $this->replace(self::report(TrendingWindow::Day, 1), [new TrendingStoryModel(
            [new StoryMemberModel($one, 101, null, $link->requireId()), new StoryMemberModel($two, 101, null)],
            self::utc('2031-05-14 08:00:00'),
            self::utc('2031-05-14 09:00:00'),
        )]);

        $this->entityManager->getConnection()
            ->executeStatement('DELETE FROM shared_link WHERE id = ?', [$link->requireId()]);

        self::assertEquals([$one => null, $two => null], $this->linksByEntry());
    }

    public function testReplacingAWindowWritesItsStoriesMembersAndSnapshot(): void
    {
        [$one, $two] = $this->twoEntries();

        $this->replace(self::report(TrendingWindow::Day, 2), [self::story($one, $two)]);
        $this->entityManager->clear();

        self::assertSame(1, $this->storyCount(TrendingWindow::Day));
        self::assertSame(2, $this->memberCount());
        $snapshot = $this->entityManager->find(TrendingSnapshot::class, 'day');
        self::assertInstanceOf(TrendingSnapshot::class, $snapshot);
        self::assertSame(7, $snapshot->getEntryCount());
        self::assertSame(2, $snapshot->getStoryCount());
        self::assertSame(311, $snapshot->getDurationMilliseconds());
        self::assertSame(4_096_000, $snapshot->getPeakMemoryBytes());
    }

    public function testReplacingADayLeavesTheWeekAndDropsTheOldDay(): void
    {
        [$one, $two] = $this->twoEntries();
        $this->replace(self::report(TrendingWindow::Day, 1), [self::story($one, $two)]);
        $this->replace(self::report(TrendingWindow::Week, 1), [self::story($one, $two)]);

        $this->replace(self::report(TrendingWindow::Day, 0), []);

        self::assertSame(0, $this->storyCount(TrendingWindow::Day));
        self::assertSame(1, $this->storyCount(TrendingWindow::Week));
        self::assertSame(2, $this->memberCount(), 'the dropped day story took its members with it');
    }

    public function testAStoryJoinsItsMembersAtTheBuildAndPlacesThemInItsWindowOnly(): void
    {
        [$one, $two] = $this->twoEntries();

        $this->replace(self::report(TrendingWindow::Week, 1), [self::story($one, $two)]);

        self::assertSame(
            [['first_joined_at' => '2031-05-14 12:00:00', 'last_joined_at' => '2031-05-14 12:00:00']],
            $this->entityManager->getConnection()
                ->fetchAllAssociative('SELECT first_joined_at, last_joined_at FROM trending_story'),
        );
        self::assertEquals(
            [
                ['entry_id' => $one, 'joined_at' => '2031-05-14 12:00:00', 'in_day' => 0, 'in_week' => 1],
                ['entry_id' => $two, 'joined_at' => '2031-05-14 12:00:00', 'in_day' => 0, 'in_week' => 1],
            ],
            $this->entityManager->getConnection()->fetchAllAssociative(
                'SELECT entry_id, joined_at, in_day, in_week FROM trending_story_entry ORDER BY entry_id',
            ),
        );
    }

    public function testEachWindowsStoriesCarryOnlyItsOwnFlag(): void
    {
        [$one, $two] = $this->twoEntries();

        $this->replace(self::report(TrendingWindow::Week, 1), [self::story($one, $two)]);
        $this->replace(self::report(TrendingWindow::Day, 1), [self::story($one, $two)]);

        self::assertEquals(
            [
                ['in_day' => 0, 'in_week' => 1],
                ['in_day' => 0, 'in_week' => 1],
                ['in_day' => 1, 'in_week' => 0],
                ['in_day' => 1, 'in_week' => 0],
            ],
            $this->entityManager->getConnection()->fetchAllAssociative(
                'SELECT in_day, in_week FROM trending_story_entry ORDER BY story_id, entry_id',
            ),
        );
    }

    public function testPurgingAnEntryRemovesItsStoryLink(): void
    {
        [$one, $two] = $this->twoEntries();
        $this->replace(self::report(TrendingWindow::Day, 1), [self::story($one, $two)]);

        $this->entityManager->getConnection()->executeStatement('DELETE FROM entry WHERE id = ?', [$one]);

        self::assertSame(1, $this->memberCount());
    }

    public function testBuiltAtIsReadBackAsUtcPerWindow(): void
    {
        $this->replace(self::report(TrendingWindow::Week, 0), []);

        $lastBuilds = $this->snapshots()->lastBuilds();

        self::assertNull($lastBuilds->builtAt(TrendingWindow::Day));
        self::assertSame(
            '2031-05-14T12:00:00+00:00',
            $lastBuilds->builtAt(TrendingWindow::Week)?->format(\DateTimeInterface::ATOM),
        );
    }

    public function testAStoryLargerThanOneInsertChunkKeepsEveryMember(): void
    {
        $fixtures = new TrendingFixtures($this->entityManager);
        $feed = $fixtures->feed('large');
        $entries = [];
        for ($index = 0; $index < 301; ++$index) {
            $entries[] = $fixtures->entry($feed, 'Entry ' . $index, '2031-05-14 08:00:00');
        }
        $fixtures->flush();
        $members = array_map(
            static fn (Entry $entry): StoryMemberModel => new StoryMemberModel($entry->requireId(), 101, null),
            $entries,
        );

        $this->replace(
            self::report(TrendingWindow::Day, 1),
            [new TrendingStoryModel($members, self::utc('2031-05-14 08:00:00'), self::utc('2031-05-14 09:00:00'))],
        );

        self::assertSame(301, $this->memberCount());
    }

    public function testAStoryRowCarriesItsBounds(): void
    {
        [$one, $two] = $this->twoEntries();
        $story = new TrendingStoryModel(
            [new StoryMemberModel($one, 101, null), new StoryMemberModel($two, 101, null)],
            self::utc('2031-05-14 08:00:00'),
            self::utc('2031-05-14 09:00:00'),
        );

        $this->replace(self::report(TrendingWindow::Day, 1), [$story]);

        $row = $this->entityManager->getConnection()
            ->fetchAssociative('SELECT first_seen, last_seen FROM trending_story');
        self::assertIsArray($row);
        self::assertSame('2031-05-14 08:00:00', $row['first_seen']);
        self::assertSame('2031-05-14 09:00:00', $row['last_seen']);
    }

    public function testEachMemberKeepsItsArticleSite(): void
    {
        [$one, $two] = $this->twoEntries();
        $three = $this->thirdEntry();
        $story = new TrendingStoryModel(
            [
                new StoryMemberModel($one, 101, 'outlet.example'),
                new StoryMemberModel($two, 202, 'shared.example'),
                new StoryMemberModel($three, 303, null),
            ],
            self::utc('2031-05-14 08:00:00'),
            self::utc('2031-05-14 09:00:00'),
        );

        $this->replace(self::report(TrendingWindow::Day, 1), [$story]);

        self::assertSame(
            [[$one, 'outlet.example'], [$two, 'shared.example'], [$three, null]],
            $this->storedSites(),
        );
    }

    public function testASiteLongerThanTheColumnIsCut(): void
    {
        [$one, $two] = $this->twoEntries();
        $longSite = str_repeat('a', 250) . '.example';
        $story = new TrendingStoryModel(
            [new StoryMemberModel($one, 101, $longSite), new StoryMemberModel($two, 202, null)],
            self::utc('2031-05-14 08:00:00'),
            self::utc('2031-05-14 09:00:00'),
        );

        $this->replace(self::report(TrendingWindow::Day, 1), [$story]);

        self::assertSame(mb_substr($longSite, 0, 255), $this->storedSites()[0][1]);
    }

    public function testDurationsAreReadBackPerWindow(): void
    {
        $this->replace(self::report(TrendingWindow::Week, 0), []);

        $lastBuilds = $this->snapshots()->lastBuilds();

        self::assertSame(5_000, $lastBuilds->expectedDurationMilliseconds(TrendingWindow::Day), 'never built');
        self::assertSame(311, $lastBuilds->expectedDurationMilliseconds(TrendingWindow::Week));
    }

    /** @param list<TrendingStoryModel> $stories */
    private function replace(TrendingBuildReportModel $report, array $stories): void
    {
        $this->snapshots()->replaceStories($report->window, $stories, $report->builtAt);
        $this->snapshots()->recordSnapshot($report);
    }

    private static function utc(string $moment): \DateTimeImmutable
    {
        return new \DateTimeImmutable($moment, new \DateTimeZone('UTC'));
    }

    /** @return array{int, int} */
    private function twoEntries(): array
    {
        $fixtures = new TrendingFixtures($this->entityManager);
        $one = $fixtures->entry($fixtures->feed('one'), 'One', '2031-05-14 08:00:00');
        $two = $fixtures->entry($fixtures->feed('two'), 'Two', '2031-05-14 09:00:00');
        $fixtures->flush();

        return [$one->requireId(), $two->requireId()];
    }

    private function thirdEntry(): int
    {
        $fixtures = new TrendingFixtures($this->entityManager);
        $three = $fixtures->entry($fixtures->feed('three'), 'Three', '2031-05-14 09:30:00');
        $fixtures->flush();

        return $three->requireId();
    }

    /** @return list<array{int, ?string}> */
    private function storedSites(): array
    {
        /** @var list<array{entry_id: int|string, article_site: string|null}> $rows */
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT entry_id, article_site FROM trending_story_entry ORDER BY entry_id',
        );

        return array_map(
            static fn (array $row): array => [(int) $row['entry_id'], $row['article_site']],
            $rows,
        );
    }

    private static function story(int $one, int $two): TrendingStoryModel
    {
        return new TrendingStoryModel(
            [new StoryMemberModel($one, 101, null), new StoryMemberModel($two, 202, null)],
            new \DateTimeImmutable('2031-05-14 08:00:00', new \DateTimeZone('UTC')),
            new \DateTimeImmutable('2031-05-14 09:00:00', new \DateTimeZone('UTC')),
        );
    }

    private static function report(TrendingWindow $window, int $storyCount): TrendingBuildReportModel
    {
        return new TrendingBuildReportModel(
            $window,
            new \DateTimeImmutable('2031-05-14 12:00:00', new \DateTimeZone('UTC')),
            7,
            $storyCount,
            311,
            4_096_000,
        );
    }

    /** @return array<int, mixed> */
    private function linksByEntry(): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllKeyValue(
            'SELECT entry_id, shared_link_id FROM trending_story_entry ORDER BY entry_id',
        );

        return array_combine(array_map(intval(...), array_keys($rows)), array_values($rows));
    }

    private function snapshots(): TrendingSnapshotRepository
    {
        $repository = $this->entityManager->getRepository(TrendingSnapshot::class);
        self::assertInstanceOf(TrendingSnapshotRepository::class, $repository);

        return $repository;
    }

    private function storyCount(TrendingWindow $window): int
    {
        $count = $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(DISTINCT member.story_id) FROM trending_story_entry member WHERE '
            . TrendingPlacementSql::placedIn($window, 'member'),
        );
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function memberCount(): int
    {
        $count = $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM trending_story_entry');
        self::assertIsNumeric($count);

        return (int) $count;
    }
}
