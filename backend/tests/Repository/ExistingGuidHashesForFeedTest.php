<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Repository\EntryRepository;
use App\Tests\DbTestCase;

/**
 * The fit-check's bounded dedupe read: which of the asked guid hashes the one
 * feed already holds, so a re-import counts as no new entries.
 */
final class ExistingGuidHashesForFeedTest extends DbTestCase
{
    public function testReturnsOnlyTheAskedHashesTheFeedHolds(): void
    {
        $one = $this->feed('https://one.example/feed.xml');
        $two = $this->feed('https://two.example/feed.xml');
        $this->entry($one, 'guid-a');
        $this->entry($one, 'guid-b');
        $this->entry($two, 'guid-a');
        $this->em->flush();

        $existing = $this->repository()->existingGuidHashesForFeed(
            $one->requireId(),
            [hash('sha256', 'guid-a'), hash('sha256', 'guid-never-written')],
        );

        self::assertSame([hash('sha256', 'guid-a')], $existing);
    }

    public function testAnEmptyListAsksForNothing(): void
    {
        self::assertSame([], $this->repository()->existingGuidHashesForFeed(1, []));
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
