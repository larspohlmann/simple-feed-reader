<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\TrendingSnapshot;
use App\Entity\User;
use App\Enum\TrendingWindow;
use App\Repository\TrendingSnapshotRepository;
use App\Service\Curation\Trending\Model\StoryMatchModel;
use App\Service\Curation\Trending\Model\TrendingBuildReportModel;
use App\Tests\DbTestCase;

final class TrendingSnapshotRepositoryTest extends DbTestCase
{
    public function testRecordingASnapshotReplacesItsWindowsRowOnly(): void
    {
        $this->snapshots()->recordSnapshot(self::report($this->userId(), TrendingWindow::Week, 4));
        $this->snapshots()->recordSnapshot(self::report($this->userId(), TrendingWindow::Day, 1));

        $this->snapshots()->recordSnapshot(self::report($this->userId(), TrendingWindow::Day, 2));
        $this->entityManager->clear();

        $snapshot = $this->entityManager->find(TrendingSnapshot::class, ['user' => $this->userId(), 'window' => 'day']);
        self::assertInstanceOf(TrendingSnapshot::class, $snapshot);
        self::assertSame(7, $snapshot->getEntryCount());
        self::assertSame(2, $snapshot->getStoryCount());
        self::assertSame(311, $snapshot->getDurationMilliseconds());
        self::assertSame(4_096_000, $snapshot->getPeakMemoryBytes());
        $week = $this->entityManager->find(TrendingSnapshot::class, ['user' => $this->userId(), 'window' => 'week']);
        self::assertSame(4, $week?->getStoryCount());
    }

    public function testLastBuildsAreTheUsers(): void
    {
        $other = $this->user('other-snapshot-reader@example.com')->requireId();
        $this->snapshots()->recordSnapshot(self::report($this->userId(), TrendingWindow::Day, 1, 12));
        $this->snapshots()->recordSnapshot(self::report($other, TrendingWindow::Week, 1, 30));

        $lastBuilds = $this->snapshots()->lastBuilds($this->userId());

        self::assertNull($lastBuilds->findBuiltAt(TrendingWindow::Week));
        self::assertSame(12, $lastBuilds->activeFeedCount(TrendingWindow::Day));
        self::assertSame(0, $lastBuilds->activeFeedCount(TrendingWindow::Week), 'never built');
    }

    public function testBuiltAtIsReadBackAsUtcPerWindow(): void
    {
        $this->snapshots()->recordSnapshot(self::report($this->userId(), TrendingWindow::Week, 0));

        $lastBuilds = $this->snapshots()->lastBuilds($this->userId());

        self::assertNull($lastBuilds->findBuiltAt(TrendingWindow::Day));
        self::assertSame(
            '2031-05-14T12:00:00+00:00',
            $lastBuilds->findBuiltAt(TrendingWindow::Week)?->format(\DateTimeInterface::ATOM),
        );
    }

    public function testDurationsAreReadBackPerWindow(): void
    {
        $this->snapshots()->recordSnapshot(self::report($this->userId(), TrendingWindow::Week, 0));

        $lastBuilds = $this->snapshots()->lastBuilds($this->userId());

        self::assertSame(5_000, $lastBuilds->expectedDurationMilliseconds(TrendingWindow::Day), 'never built');
        self::assertSame(311, $lastBuilds->expectedDurationMilliseconds(TrendingWindow::Week));
    }

    private static function report(
        int $userId,
        TrendingWindow $window,
        int $storyCount,
        int $activeFeedCount = 9,
    ): TrendingBuildReportModel {
        return new TrendingBuildReportModel(
            $userId,
            $window,
            new \DateTimeImmutable('2031-05-14 12:00:00', new \DateTimeZone('UTC')),
            7,
            $activeFeedCount,
            $storyCount,
            311,
            4_096_000,
            new StoryMatchModel([], [], 0),
            0,
        );
    }

    private function userId(): int
    {
        return $this->user()->requireId();
    }

    private function user(string $email = 'snapshot-reader@example.com'): User
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email])
            ?? new User($email, new \DateTimeImmutable('2031-01-01 00:00:00'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function snapshots(): TrendingSnapshotRepository
    {
        $repository = $this->entityManager->getRepository(TrendingSnapshot::class);
        self::assertInstanceOf(TrendingSnapshotRepository::class, $repository);

        return $repository;
    }
}
