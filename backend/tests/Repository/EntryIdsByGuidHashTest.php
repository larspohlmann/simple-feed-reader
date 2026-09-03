<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Repository\EntryRepository;
use App\Tests\DbTestCase;

/**
 * The restore's post-insert read-back (#456): the ids of the rows one batch
 * just wrote, and nothing else of the feed.
 */
final class EntryIdsByGuidHashTest extends DbTestCase
{
    public function testReturnsTheAskedHashesOfTheOneFeedOnly(): void
    {
        $one = $this->feed('https://one.example/feed.xml');
        $two = $this->feed('https://two.example/feed.xml');
        $wanted = $this->entry($one, 'guid-a');
        $this->entry($one, 'guid-b');
        $this->entry($two, 'guid-a');
        $this->em->flush();

        $ids = $this->repository()->entryIdsByGuidHash(
            (int) $one->getId(),
            [hash('sha256', 'guid-a'), hash('sha256', 'guid-never-written')],
        );

        self::assertSame([hash('sha256', 'guid-a') => (int) $wanted->getId()], $ids);
    }

    public function testAnEmptyListAsksForNothing(): void
    {
        self::assertSame([], $this->repository()->entryIdsByGuidHash(1, []));
    }

    private function feed(string $url): Feed
    {
        $feed = new Feed($url);
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
            new \DateTimeImmutable('2026-08-02 06:00:00'),
            new \DateTimeImmutable('2026-08-02 05:00:00'),
        );
        $this->em->persist($entry);

        return $entry;
    }

    private function repository(): EntryRepository
    {
        $repository = $this->em->getRepository(Entry::class);
        self::assertInstanceOf(EntryRepository::class, $repository);

        return $repository;
    }
}
