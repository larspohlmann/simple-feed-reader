<?php

declare(strict_types=1);

namespace App\Tests\Service\Trending;

use App\Entity\TrendingSnapshot;
use App\Enum\SocialNetwork;
use App\Enum\TrendingWindow;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Trending\TrendingSnapshotBuilder;
use App\Tests\DbTestCase;
use App\Tests\Support\TrendingFixtures;
use App\Tests\Support\TrendingWiring;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\MockClock;

final class TrendingSnapshotBuilderTest extends DbTestCase
{
    private const string NOW = '2031-05-14 12:00:00';

    private TrendingFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtures = new TrendingFixtures($this->entityManager);
    }

    public function testGroupsTheDaysHeadlinesIntoAStoryAndRecordsTheSnapshot(): void
    {
        $this->fixtures->hafenstreik($this->fixtures->feed('alpha'), 0, '2031-05-14 08:00:00');
        $this->fixtures->hafenstreik($this->fixtures->feed('beta'), 1, '2031-05-14 09:00:00');
        $this->fixtures->hafenstreik($this->fixtures->feed('gamma'), 2, '2031-05-14 10:00:00');
        $this->fixtures->entry($this->fixtures->feed('delta'), 'Wetter morgen sonnig', '2031-05-14 11:00:00');
        $this->fixtures->hafenstreik($this->fixtures->feed('epsilon'), 0, '2031-05-13 11:59:59');
        $this->fixtures->flush();

        $report = $this->builder()->build(TrendingWindow::Day);
        $this->entityManager->clear();

        self::assertSame(4, $report->entryCount, 'the entry a second before the window is not read');
        self::assertSame(1, $report->storyCount);
        self::assertSame(
            [['outlet_count' => 3, 'first_seen' => '2031-05-14 08:00:00', 'last_seen' => '2031-05-14 10:00:00']],
            $this->storedStories(TrendingWindow::Day),
        );
        self::assertSame(3, $this->memberCount());
        $snapshot = $this->entityManager->find(TrendingSnapshot::class, 'day');
        self::assertInstanceOf(TrendingSnapshot::class, $snapshot);
        self::assertSame(self::NOW, $snapshot->getBuiltAt()->format('Y-m-d H:i:s'));
        self::assertSame(4, $snapshot->getEntryCount());
        self::assertGreaterThan(0, $snapshot->getPeakMemoryBytes());
    }

    public function testAGroupOfTwoFeedsIsTooNarrowForTheDay(): void
    {
        $this->fixtures->hafenstreik($this->fixtures->feed('alpha'), 0, '2031-05-14 08:00:00');
        $this->fixtures->hafenstreik($this->fixtures->feed('beta'), 1, '2031-05-14 09:00:00');
        $this->fixtures->flush();

        self::assertSame(0, $this->builder()->build(TrendingWindow::Day)->storyCount);
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

        $report = $this->builder()->build(TrendingWindow::Day);

        self::assertSame(1, $report->storyCount);
        self::assertSame(4, $this->memberCount());
    }

    public function testRebuildingTheDayLeavesTheWeekAlone(): void
    {
        foreach (['alpha', 'beta', 'gamma', 'delta', 'omega'] as $variant => $name) {
            $this->fixtures->hafenstreik($this->fixtures->feed($name), $variant, '2031-05-14 10:00:00');
        }
        $this->fixtures->flush();
        $this->builder()->build(TrendingWindow::Week);

        $this->builder()->build(TrendingWindow::Day);

        self::assertCount(1, $this->storedStories(TrendingWindow::Week));
        self::assertCount(1, $this->storedStories(TrendingWindow::Day));
    }

    public function testAFailureAfterTheReplacementLeavesTheOldSnapshotInPlace(): void
    {
        $oldMember = $this->fixtures->hafenstreik($this->fixtures->feed('old'), 0, '2031-05-14 01:00:00');
        $this->fixtures->story(TrendingWindow::Day, '2031-05-14 01:00:00', $oldMember);
        $this->entityManager->persist(
            new TrendingSnapshot(TrendingWindow::Day, new \DateTimeImmutable('2031-05-14 11:00:00'), 1, 1, 0, 0),
        );
        $this->fixtures->hafenstreik($this->fixtures->feed('alpha'), 0, '2031-05-14 08:00:00');
        $this->fixtures->hafenstreik($this->fixtures->feed('beta'), 1, '2031-05-14 09:00:00');
        $this->fixtures->hafenstreik($this->fixtures->feed('gamma'), 2, '2031-05-14 10:00:00');
        $this->fixtures->flush();
        $clock = new MockClock(self::NOW, 'UTC');
        $builder = new TrendingSnapshotBuilder(
            TrendingWiring::grouping($this->entityManager, $clock),
            TrendingWiring::snapshots($this->entityManager),
            $this->failingAfterTheTransactionBody(),
            new NaiveUtcClock($clock),
        );

        try {
            $builder->build(TrendingWindow::Day);
            self::fail('the failure after the replacement must surface');
        } catch (\RuntimeException) {
            // expected: the transaction rolls back
        }

        self::assertSame(
            [['stories' => 1, 'built_at' => '2031-05-14 11:00:00']],
            $this->entityManager->getConnection()->fetchAllAssociative(
                'SELECT (SELECT COUNT(*) FROM trending_story WHERE time_window = \'day\') AS stories,'
                . ' built_at FROM trending_snapshot WHERE time_window = \'day\'',
            ),
        );
    }

    private function failingAfterTheTransactionBody(): EntityManagerInterface
    {
        return new class ($this->entityManager) extends EntityManagerDecorator {
            public function wrapInTransaction(callable $func): mixed
            {
                return parent::wrapInTransaction(
                    static function (EntityManagerInterface $entityManager) use ($func): never {
                        $func($entityManager);

                        throw new \RuntimeException('Simulated failure after the replacement.');
                    },
                );
            }
        };
    }

    private function builder(): TrendingSnapshotBuilder
    {
        $clock = new MockClock(self::NOW, 'UTC');

        return new TrendingSnapshotBuilder(
            TrendingWiring::grouping($this->entityManager, $clock),
            TrendingWiring::snapshots($this->entityManager),
            $this->entityManager,
            new NaiveUtcClock($clock),
        );
    }

    /** @return list<array<string, mixed>> */
    private function storedStories(TrendingWindow $window): array
    {
        /** @var list<array{outlet_count: int|string, first_seen: string, last_seen: string}> $rows */
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT outlet_count, first_seen, last_seen FROM trending_story WHERE time_window = ?',
            [$window->value],
        );

        return array_map(
            static fn (array $row): array => ['outlet_count' => (int) $row['outlet_count']] + $row,
            $rows,
        );
    }

    private function memberCount(): int
    {
        $count = $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM trending_story_entry');
        self::assertIsNumeric($count);

        return (int) $count;
    }
}
