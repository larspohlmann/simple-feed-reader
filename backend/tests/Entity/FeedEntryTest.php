<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Enum\FeedStatus;
use App\Tests\DbTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class FeedEntryTest extends DbTestCase
{
    public function testFeedDefaults(): void
    {
        $feed = new Feed('https://example.com/feed.xml');
        $this->em->persist($feed);
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->em->getRepository(Feed::class)->findOneBy(['url' => 'https://example.com/feed.xml']);

        self::assertNotNull($reloaded);
        self::assertSame(FeedStatus::Active, $reloaded->getStatus());
        self::assertSame(60, $reloaded->getFetchIntervalMinutes());
        self::assertSame(0, $reloaded->getConsecutiveFailures());
        self::assertNull($reloaded->getEtag());
    }

    public function testFeedUrlIsUnique(): void
    {
        $this->em->persist(new Feed('https://dup.example.com/feed.xml'));
        $this->em->flush();

        $this->em->persist(new Feed('https://dup.example.com/feed.xml'));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testEntryGuidHashIsComputedAndUniquePerFeed(): void
    {
        $feed = new Feed('https://example.com/a.xml');
        $this->em->persist($feed);

        $entry = new Entry(
            feed: $feed,
            guid: 'urn:uuid:1234',
            url: 'https://example.com/post/1',
            title: 'First post',
            createdAt: new \DateTimeImmutable(),
        );
        $this->em->persist($entry);
        $this->em->flush();

        self::assertSame(hash('sha256', 'urn:uuid:1234'), $entry->getGuidHash());

        $duplicate = new Entry(
            feed: $feed,
            guid: 'urn:uuid:1234',
            url: 'https://example.com/post/1-copy',
            title: 'Same guid again',
            createdAt: new \DateTimeImmutable(),
        );
        $this->em->persist($duplicate);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testSameGuidOnDifferentFeedsIsAllowed(): void
    {
        $feedA = new Feed('https://a.example.com/feed.xml');
        $feedB = new Feed('https://b.example.com/feed.xml');
        $this->em->persist($feedA);
        $this->em->persist($feedB);
        $now = new \DateTimeImmutable();

        $this->em->persist(new Entry($feedA, 'shared-guid', 'https://a.example.com/1', 'A', $now));
        $this->em->persist(new Entry($feedB, 'shared-guid', 'https://b.example.com/1', 'B', $now));
        $this->em->flush();

        $this->addToAssertionCount(1);
    }

    public function testDeletingFeedCascadesToEntries(): void
    {
        $feed = new Feed('https://cascade.example.com/feed.xml');
        $this->em->persist($feed);
        $entry = new Entry($feed, 'guid-c', 'https://cascade.example.com/1', 'Post', new \DateTimeImmutable());
        $this->em->persist($entry);
        $this->em->flush();
        $entryId = $entry->getId();
        $feedId = $feed->getId();
        $this->em->clear();

        $reloadedFeed = $this->em->find(Feed::class, $feedId);
        self::assertNotNull($reloadedFeed);
        $this->em->remove($reloadedFeed);
        $this->em->flush();
        $this->em->clear();

        self::assertNull($this->em->find(Entry::class, $entryId));
    }
}
