<?php

declare(strict_types=1);

namespace App\Tests\Service\Chapter;

use App\Entity\Entry;
use App\Entity\EntryChapter;
use App\Entity\Feed;
use App\Service\Chapter\ChapterFileSource\ChapterFileSourceInterface;
use App\Service\Chapter\ChapterFiller;
use App\Service\Chapter\Exception\ChapterFileUnreachableException;
use App\Service\Fetch\Exception\FeedUnreachableException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ChapterFillerTest extends TestCase
{
    public function testFillsAPendingFileOnceAndFlushes(): void
    {
        $entry = self::episode('https://cdn.test/ch.json');
        $files = $this->createMock(ChapterFileSourceInterface::class);
        $files->expects($this->once())->method('chaptersAt')->with('https://cdn.test/ch.json')
            ->willReturn([new EntryChapter(0, 'A'), new EntryChapter(60, 'B')]);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        (new ChapterFiller($files, $entityManager, new NullLogger()))->fillOnOpen($entry);

        self::assertCount(2, $entry->getChapters());
        self::assertNull($entry->getChaptersUrl());
    }

    public function testAnEntryWithNothingPendingFetchesNothing(): void
    {
        $files = $this->createMock(ChapterFileSourceInterface::class);
        $files->expects($this->never())->method('chaptersAt');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        (new ChapterFiller($files, $entityManager, new NullLogger()))->fillOnOpen(self::episode(null));
    }

    public function testAnUnreachableFileStaysPending(): void
    {
        $entry = self::episode('https://cdn.test/ch.json');
        $files = $this->createStub(ChapterFileSourceInterface::class);
        $files->method('chaptersAt')->willThrowException(
            ChapterFileUnreachableException::from('https://cdn.test/ch.json', new FeedUnreachableException('reset')),
        );
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        (new ChapterFiller($files, $entityManager, new NullLogger()))->fillOnOpen($entry);

        self::assertSame('https://cdn.test/ch.json', $entry->getChaptersUrl());
    }

    private static function episode(?string $chaptersUrl): Entry
    {
        $entry = new Entry(
            new Feed('https://cdn.test/feed.xml'),
            'g1',
            'https://cdn.test/ep1',
            'Episode 1',
            new \DateTimeImmutable('2026-10-01T00:00:00Z'),
            new \DateTimeImmutable('2026-10-01T00:00:00Z'),
        );
        $entry->declareChapters([], $chaptersUrl);

        return $entry;
    }
}
