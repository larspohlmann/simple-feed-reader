<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Repository\PendingImageVerificationRepository;
use App\Tests\DbTestCase;

final class PendingImageVerificationTest extends DbTestCase
{
    public function testReturnsOnlyEntriesWithAPendingImage(): void
    {
        $feed = $this->feed();
        $pending = $this->entry($feed, 'pending');
        $pending->getImage()->storePending('https://i/pending.jpg', null, null);
        $verified = $this->entry($feed, 'verified');
        $verified->getImage()->storeVerified(
            'https://i/verified.jpg',
            800,
            600,
            new \DateTimeImmutable('2026-09-21 10:00:00'),
        );
        $this->entry($feed, 'no-image');
        $this->em->flush();

        $found = $this->repository()->findPendingImageVerification(50);

        $guids = array_map(static fn (Entry $entry): string => $entry->getGuid(), $found);
        self::assertContains('pending', $guids);
        self::assertNotContains('verified', $guids);
        self::assertNotContains('no-image', $guids);
    }

    public function testHonoursTheLimit(): void
    {
        $feed = $this->feed();
        foreach (['a', 'b', 'c'] as $guid) {
            $this->entry($feed, $guid)->getImage()->storePending('https://i/' . $guid . '.jpg', null, null);
        }
        $this->em->flush();

        self::assertCount(2, $this->repository()->findPendingImageVerification(2));
    }

    private function feed(): Feed
    {
        $feed = new Feed('https://example.test/feed.xml');
        $this->em->persist($feed);

        return $feed;
    }

    private function entry(Feed $feed, string $guid): Entry
    {
        $entry = new Entry(
            $feed,
            $guid,
            'https://example.test/' . $guid,
            'Title ' . $guid,
            new \DateTimeImmutable('2026-09-21 06:00:00'),
            new \DateTimeImmutable('2026-09-21 05:00:00'),
        );
        $this->em->persist($entry);

        return $entry;
    }

    private function repository(): PendingImageVerificationRepository
    {
        $repository = self::getContainer()->get(PendingImageVerificationRepository::class);
        self::assertInstanceOf(PendingImageVerificationRepository::class, $repository);

        return $repository;
    }
}
