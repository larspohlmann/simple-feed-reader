<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\PendingPostEnrichment;
use App\Repository\PendingPostEnrichmentRepository;
use App\Tests\DbTestCase;

final class PendingPostEnrichmentRepositoryTest extends DbTestCase
{
    public function testFindsAFeedsRowsOldestFirstUpToTheLimit(): void
    {
        $feed = $this->feed('https://bsky.app/profile/a/rss');
        $other = $this->feed('https://bsky.app/profile/b/rss');
        $this->queued($feed, 'at://did:plc:a/app.bsky.feed.post/new', '2026-10-10 11:00:00');
        $this->queued($feed, 'at://did:plc:a/app.bsky.feed.post/old', '2026-10-10 09:00:00');
        $this->queued($feed, 'at://did:plc:a/app.bsky.feed.post/mid', '2026-10-10 10:00:00');
        $this->queued($other, 'at://did:plc:b/app.bsky.feed.post/elsewhere', '2026-10-10 08:00:00');
        $this->entityManager->flush();

        $rows = $this->repository()->findOldestForFeed($feed, 2);

        self::assertSame(
            ['at://did:plc:a/app.bsky.feed.post/old', 'at://did:plc:a/app.bsky.feed.post/mid'],
            self::guidsOf($rows),
        );
    }

    public function testDeletesOnlyTheFeedsRowsQueuedBeforeTheCutoff(): void
    {
        $feed = $this->feed('https://bsky.app/profile/a/rss');
        $other = $this->feed('https://bsky.app/profile/b/rss');
        $this->queued($feed, 'at://did:plc:a/app.bsky.feed.post/old', '2026-10-10 09:59:59');
        $this->queued($feed, 'at://did:plc:a/app.bsky.feed.post/at-cutoff', '2026-10-10 10:00:00');
        $this->queued($other, 'at://did:plc:b/app.bsky.feed.post/elsewhere', '2026-10-10 08:00:00');
        $this->entityManager->flush();

        $this->repository()->deleteQueuedBefore($feed, new \DateTimeImmutable('2026-10-10 10:00:00'));

        self::assertSame(
            ['at://did:plc:a/app.bsky.feed.post/at-cutoff'],
            self::guidsOf($this->repository()->findOldestForFeed($feed, 10)),
        );
        self::assertCount(1, $this->repository()->findOldestForFeed($other, 10));
    }

    public function testARowGoesWithItsEntry(): void
    {
        $feed = $this->feed('https://bsky.app/profile/a/rss');
        $entry = $this->queued($feed, 'at://did:plc:a/app.bsky.feed.post/gone', '2026-10-10 09:00:00')->getEntry();
        $this->entityManager->flush();
        $connection = $this->entityManager->getConnection();

        $connection->executeStatement('DELETE FROM entry WHERE id = ?', [$entry->requireId()]);

        self::assertSame([], $this->repository()->findAll());
    }

    /**
     * @param list<PendingPostEnrichment> $rows
     *
     * @return list<string>
     */
    private static function guidsOf(array $rows): array
    {
        return array_map(static fn (PendingPostEnrichment $pending): string => $pending->getEntry()->getGuid(), $rows);
    }

    private function feed(string $url): Feed
    {
        $feed = new Feed($url);
        $this->entityManager->persist($feed);

        return $feed;
    }

    private function queued(Feed $feed, string $guid, string $queuedAt): PendingPostEnrichment
    {
        $entry = new Entry(
            $feed,
            $guid,
            null,
            'Post',
            new \DateTimeImmutable('2026-10-10 08:00:00'),
            new \DateTimeImmutable('2026-10-10 08:00:00'),
        );
        $pending = new PendingPostEnrichment($entry, new \DateTimeImmutable($queuedAt));
        $this->entityManager->persist($entry);
        $this->entityManager->persist($pending);

        return $pending;
    }

    private function repository(): PendingPostEnrichmentRepository
    {
        $repository = self::getContainer()->get(PendingPostEnrichmentRepository::class);
        self::assertInstanceOf(PendingPostEnrichmentRepository::class, $repository);

        return $repository;
    }
}
