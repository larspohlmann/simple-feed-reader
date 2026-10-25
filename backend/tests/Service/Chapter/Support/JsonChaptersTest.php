<?php

declare(strict_types=1);

namespace App\Tests\Service\Chapter\Support;

use App\Entity\EntryChapter;
use App\Service\Chapter\Support\JsonChapters;
use PHPUnit\Framework\TestCase;

final class JsonChaptersTest extends TestCase
{
    public function testReadsPodcastingTwoChapters(): void
    {
        $chapters = JsonChapters::from(['version' => '1.2.0', 'chapters' => [
            ['startTime' => 0, 'title' => 'Intro'],
            ['startTime' => 95.5, 'title' => 'News', 'img' => 'https://x.test/i.png'],
        ]]);

        self::assertEquals([new EntryChapter(0, 'Intro'), new EntryChapter(95, 'News')], $chapters);
    }

    public function testDropsChaptersHiddenFromTheTableOfContents(): void
    {
        $chapters = JsonChapters::from(['chapters' => [
            ['startTime' => 0, 'title' => 'Intro'],
            ['startTime' => 30, 'title' => 'Ad', 'toc' => false],
            ['startTime' => 60, 'title' => 'Talk'],
        ]]);

        self::assertSame(
            ['Intro', 'Talk'],
            array_map(static fn (EntryChapter $chapter): string => $chapter->title, $chapters),
        );
    }

    public function testSkipsMalformedRowsAndMalformedFiles(): void
    {
        self::assertSame([], JsonChapters::from([]));
        self::assertSame([], JsonChapters::from(['chapters' => 'nope']));
        self::assertEquals(
            [new EntryChapter(0, 'A'), new EntryChapter(9, 'B')],
            JsonChapters::from(['chapters' => [
                ['startTime' => 0, 'title' => 'A'], ['title' => 'no start'], ['startTime' => -4, 'title' => 'neg'],
                ['startTime' => 5, 'title' => ''], 'row', ['startTime' => 9, 'title' => 'B'],
            ]]),
        );
    }
}
