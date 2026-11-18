<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\EntryLemmyPost;
use App\Entity\Feed;
use App\Repository\EntryLemmyPostRepository;
use App\Tests\DbTestCase;

final class EntryLemmyPostRepositoryTest extends DbTestCase
{
    private EntryLemmyPostRepository $posts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->posts = self::getContainer()->get(EntryLemmyPostRepository::class);
    }

    public function testRowsComeKeyedByEntryIdAndEntriesWithoutOneAreAbsent(): void
    {
        $feed = new Feed('https://lemmy.test/feed.xml');
        $this->entityManager->persist($feed);
        $withRow = $this->persistedEntry($feed, 'with');
        $withoutRow = $this->persistedEntry($feed, 'without');
        $row = new EntryLemmyPost($withRow);
        $row->recordScore(5);
        $this->entityManager->persist($row);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $found = $this->posts->byEntryIds([$withRow->requireId(), $withoutRow->requireId()]);

        self::assertSame([$withRow->requireId()], array_keys($found));
        self::assertSame(5, $found[$withRow->requireId()]->getScore());
    }

    public function testNoIdsAskNothing(): void
    {
        self::assertSame([], $this->posts->byEntryIds([]));
    }

    public function testRemovingTheEntryRemovesItsRow(): void
    {
        $feed = new Feed('https://lemmy.test/feed.xml');
        $this->entityManager->persist($feed);
        $entry = $this->persistedEntry($feed, 'gone');
        $this->entityManager->persist(new EntryLemmyPost($entry));
        $this->entityManager->flush();

        $this->entityManager->remove($entry);
        $this->entityManager->flush();

        self::assertSame([], $this->posts->findAll());
    }

    private function persistedEntry(Feed $feed, string $guid): Entry
    {
        $entry = new Entry(
            $feed,
            $guid,
            'https://lemmy.test/' . $guid,
            $guid,
            new \DateTimeImmutable('2026-11-18 06:00:00'),
            new \DateTimeImmutable('2026-11-18 05:00:00'),
        );
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }
}
