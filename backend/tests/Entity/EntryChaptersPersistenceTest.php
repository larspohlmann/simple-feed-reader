<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Entry;
use App\Entity\EntryChapter;
use App\Entity\Feed;
use App\Tests\DbTestCase;

final class EntryChaptersPersistenceTest extends DbTestCase
{
    public function testChaptersSurviveAReloadAndTheUrlIsForgottenOnFill(): void
    {
        $entry = $this->persistedEntry('guid-chapters');
        $entry->declareChapters([], 'https://cdn.test/ch.json');
        $this->entityManager->flush();
        $this->entityManager->clear();

        $linked = $this->reload($entry);
        self::assertSame([], $linked->getChapters());
        self::assertSame('https://cdn.test/ch.json', $linked->getChaptersUrl());

        $linked->fillChapters([new EntryChapter(0, 'Intro'), new EntryChapter(60, 'Talk')]);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $filled = $this->reload($entry);
        self::assertEquals([new EntryChapter(0, 'Intro'), new EntryChapter(60, 'Talk')], $filled->getChapters());
        self::assertNull($filled->getChaptersUrl());
    }

    public function testDeclaredChaptersReadBackThroughTheEntry(): void
    {
        $entry = $this->persistedEntry('guid-declared');

        $chapters = [new EntryChapter(0, 'Intro'), new EntryChapter(90, 'Main')];
        $entry->declareChapters($chapters, 'https://cdn.test/x.json');
        $this->entityManager->flush();
        $this->entityManager->clear();

        $reloaded = $this->reload($entry);
        self::assertEquals($chapters, $reloaded->getChapters());
        self::assertNull($reloaded->getChaptersUrl());
    }

    private function persistedEntry(string $guid): Entry
    {
        $feed = new Feed('https://chapters-persist.example/feed.xml');
        $this->entityManager->persist($feed);
        $entry = new Entry(
            $feed,
            $guid,
            'https://chapters-persist.example/' . $guid,
            'Episode',
            new \DateTimeImmutable('2026-10-01T00:00:00Z'),
            new \DateTimeImmutable('2026-10-01T00:00:00Z'),
        );
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function reload(Entry $entry): Entry
    {
        $reloaded = $this->entityManager->find(Entry::class, $entry->requireId());
        self::assertInstanceOf(Entry::class, $reloaded);

        return $reloaded;
    }
}
