<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\LinkCardContent;
use App\Entity\SharedLinkResolution;
use App\Enum\LinkResolution;
use App\Repository\SharedLinkResolutionRepository;
use App\Service\Content\LinkResolution\Model\LinkLandingModel;
use App\Service\Content\LinkResolution\Model\PendingLinkModel;
use App\Tests\DbTestCase;
use App\Tests\Support\SharedLinks;

final class SharedLinkResolutionRepositoryTest extends DbTestCase
{
    private const string NOW = '2026-11-15 12:00:00';

    public function testPendingResolutionsAreReadInIdOrder(): void
    {
        $first = $this->linkOf('https://n.pr/a');
        $this->linkOf('https://example.com/2026/a-story');
        $second = $this->linkOf('https://n.pr/b');
        $this->linkOf('https://n.pr/c');

        self::assertEquals(
            [new PendingLinkModel($first, 'https://n.pr/a', 0), new PendingLinkModel($second, 'https://n.pr/b', 0)],
            $this->repository()->findDuePending(new \DateTimeImmutable(self::NOW), 2),
        );
    }

    public function testASettledRowIsNotPending(): void
    {
        $linkId = $this->linkOf('https://n.pr/a');
        $this->repository()->settle(new PendingLinkModel($linkId, 'https://n.pr/a', 0), LinkLandingModel::unchanged());

        self::assertSame([], $this->repository()->findDuePending(new \DateTimeImmutable(self::NOW), 5));
    }

    public function testADeferredRowWaitsForItsRetry(): void
    {
        $linkId = $this->linkOf('https://n.pr/a');
        $this->repository()->defer(
            new PendingLinkModel($linkId, 'https://n.pr/a', 0),
            new \DateTimeImmutable('2026-11-15 13:00:00'),
        );

        self::assertSame([], $this->repository()->findDuePending(new \DateTimeImmutable('2026-11-15 12:59:59'), 5));
        self::assertEquals(
            [new PendingLinkModel($linkId, 'https://n.pr/a', 1)],
            $this->repository()->findDuePending(new \DateTimeImmutable('2026-11-15 13:00:00'), 5),
        );
    }

    public function testASettledRowStoresItsLanding(): void
    {
        $linkId = $this->linkOf('https://n.pr/a');

        $this->repository()->settle(
            new PendingLinkModel($linkId, 'https://n.pr/a', 0),
            LinkLandingModel::resolved('https://www.npr.org/a', 'hash-a'),
        );

        $stored = $this->stored($linkId);
        self::assertSame(LinkResolution::Resolved, $stored->getStatus());
        self::assertSame('https://www.npr.org/a', $stored->getResolvedUrl());
        self::assertSame('hash-a', $stored->getResolvedUrlHash());
        self::assertSame(1, $stored->getAttempts());
    }

    public function testASettleClearsTheRetry(): void
    {
        $linkId = $this->linkOf('https://n.pr/a');
        $this->repository()->defer(
            new PendingLinkModel($linkId, 'https://n.pr/a', 0),
            new \DateTimeImmutable('2026-11-15 13:00:00'),
        );

        $this->repository()->settle(new PendingLinkModel($linkId, 'https://n.pr/a', 1), LinkLandingModel::unresolved());

        $stored = $this->stored($linkId);
        self::assertSame(LinkResolution::Unresolved, $stored->getStatus());
        self::assertNull($stored->getRetryAt());
        self::assertSame(2, $stored->getAttempts());
    }

    public function testASettledRowIsNotSettledAgain(): void
    {
        $linkId = $this->linkOf('https://n.pr/a');
        $pending = new PendingLinkModel($linkId, 'https://n.pr/a', 0);

        $this->repository()->settle($pending, LinkLandingModel::resolved('https://a.example/a', 'hash-a'));
        $this->repository()->settle($pending, LinkLandingModel::resolved('https://b.example/b', 'hash-b'));
        $this->repository()->defer($pending, new \DateTimeImmutable('2026-11-15 13:00:00'));

        $stored = $this->stored($linkId);
        self::assertSame('https://a.example/a', $stored->getResolvedUrl());
        self::assertSame(1, $stored->getAttempts());
        self::assertNull($stored->getRetryAt());
    }

    public function testAStaleAttemptIsNotRecorded(): void
    {
        $linkId = $this->linkOf('https://n.pr/a');
        $this->repository()->defer(
            new PendingLinkModel($linkId, 'https://n.pr/a', 0),
            new \DateTimeImmutable('2026-11-15 13:00:00'),
        );

        $this->repository()->settle(new PendingLinkModel($linkId, 'https://n.pr/a', 0), LinkLandingModel::unchanged());

        self::assertSame(LinkResolution::Pending, $this->stored($linkId)->getStatus());
    }

    private function linkOf(string $url): int
    {
        return SharedLinks::of(
            $this->entityManager,
            new LinkCardContent($url, 'Title', null, null, 'example.com'),
        )->requireId();
    }

    private function stored(int $linkId): SharedLinkResolution
    {
        $this->entityManager->clear();
        $stored = $this->entityManager->find(SharedLinkResolution::class, $linkId);
        self::assertInstanceOf(SharedLinkResolution::class, $stored);

        return $stored;
    }

    private function repository(): SharedLinkResolutionRepository
    {
        $repository = self::getContainer()->get(SharedLinkResolutionRepository::class);
        self::assertInstanceOf(SharedLinkResolutionRepository::class, $repository);

        return $repository;
    }
}
