<?php

declare(strict_types=1);

namespace App\Tests\Service\Chapter\Support;

use App\Entity\EntryChapter;
use App\Service\Chapter\Model\StampPosition;
use App\Service\Chapter\Support\ChapterLine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChapterLineTest extends TestCase
{
    /** @return iterable<string, array{string, int, string}> */
    public static function chapterLines(): iterable
    {
        yield 'a stamp after a number prefix' => ['01. 00:00 IKØN - A World', 0, '01. IKØN - A World'];
        yield 'a bracketed stamp at the end' => ['1. Disclosure Day (00:00)', 0, '1. Disclosure Day'];
        yield 'a bare stamp at the end' => ['1. Title (Iboga Records) 00:00', 0, '1. Title (Iboga Records)'];
        yield 'a square-bracketed stamp and a dash' => ['[12:30] - Middle', 750, 'Middle'];
        yield 'hours and a bar' => ['1:02:03 | End', 3723, 'End'];
        yield 'hours with a zero' => ['0:00:00 Migdalor Remix ID (Unreleased)', 0, 'Migdalor Remix ID (Unreleased)'];
        yield 'separators at both ends' => ['0:10 – Intro: –', 10, 'Intro'];
        yield 'a stamp after a bare number prefix' => ['06  21:36 A', 1296, '06 A'];
        yield 'a stamp after a parenthesised number prefix' => ['1) 0:00 A', 0, '1) A'];
        yield 'a leading range' => ['0:00 - 1:00 A', 0, 'A'];
        yield 'a leading range with an en dash and no spaces' => ['1:00–2:00 | B', 60, 'B'];
        yield 'a leading range with a tilde' => ['2:00 ~ 3:00 C', 120, 'C'];
    }

    #[DataProvider('chapterLines')]
    public function testReadsTheStampAndTheTextAroundIt(string $text, int $start, string $title): void
    {
        self::assertEquals(new EntryChapter($start, $title), ChapterLine::chapterOf($text));
    }

    /** @return iterable<string, array{string}> */
    public static function notChapterLines(): iterable
    {
        yield 'two stamps' => ['0:00 to 1:00 A'];
        yield 'a range that does not lead' => ['A 0:00 - 1:00'];
        yield 'a bare range' => ['0:00 - 1:00'];
        yield 'three stamps' => ['0:00 - 1:00 - 2:00 A'];
        yield 'a bare stamp' => ['00:00'];
        yield 'a bare bracketed stamp' => [' (00:00) - '];
        yield 'minutes past 59' => ['1:75:00 A'];
        yield 'no stamp' => ['Sponsor'];
        yield 'seconds with a third digit' => ['0:123 A'];
        yield 'a longer colon run' => ['1:2:3:4 A'];
        yield 'a stamp inside the line' => ['A 4:05 B'];
        yield 'a stamp inside prose' => ['Live stream every Friday at 20:00 CET'];
        yield 'a stamp before trailing separators' => ['— Intro: 0:10 –'];
        yield 'a stamp after a word that is no number prefix' => ['Track 1 0:00 A'];
    }

    #[DataProvider('notChapterLines')]
    public function testALineWithoutExactlyOneStampAndATitleIsNoChapter(string $text): void
    {
        self::assertNull(ChapterLine::chapterOf($text));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function stampedOrNot(): iterable
    {
        yield 'a chapter line' => ['0:00 A', false];
        yield 'two stamps' => ['0:00 to 1:00 A', false];
        yield 'a bare stamp' => ['00:00', false];
        yield 'plain text' => ['BUY https://example.test/track', true];
        yield 'seconds with a third digit' => ['0:123 A', true];
        yield 'a longer colon run' => ['1:2:3:4 A', true];
        yield 'a time parameter in a link' => ['https://youtu.be/x?t=1:30 watch', true];
        yield 'empty' => ['', true];
    }

    #[DataProvider('stampedOrNot')]
    public function testTellsALineWithoutAnyStamp(string $text, bool $holdsNoStamp): void
    {
        self::assertSame($holdsNoStamp, ChapterLine::holdsNoStamp($text));
    }

    /** @return iterable<string, array{string, StampPosition}> */
    public static function stampPositions(): iterable
    {
        yield 'a stamp that starts the line' => ['0:00 A', StampPosition::Leading];
        yield 'a bracketed stamp that starts the line' => ['  [12:30] - Middle', StampPosition::Leading];
        yield 'a stamp after a dotted number' => ['01. 00:00 A', StampPosition::Leading];
        yield 'a stamp after a bare number' => ['06  21:36 A', StampPosition::Leading];
        yield 'a stamp after a parenthesised number' => ['1) (0:00) A', StampPosition::Leading];
        yield 'a leading range' => ['0:00 - 1:00 A', StampPosition::Leading];
        yield 'a stamp that ends the line' => ['1. A (Label) 00:00', StampPosition::Trailing];
        yield 'a bracketed stamp that ends the line' => ['1. A (00:00)  ', StampPosition::Trailing];
        yield 'a square-bracketed stamp that ends the line' => ['Recorded at [21:30]', StampPosition::Trailing];
        yield 'a stamp inside the line' => ['A 4:05 B', StampPosition::Inner];
        yield 'a stamp after a word' => ['Track 1 0:00 A', StampPosition::Inner];
        yield 'a stamp before a separator at the end' => ['Intro 0:10 –', StampPosition::Inner];
        yield 'a stamp after a separator at the start' => ['— 0:10 Intro', StampPosition::Inner];
    }

    #[DataProvider('stampPositions')]
    public function testTellsWhereTheStampSits(string $text, StampPosition $position): void
    {
        self::assertSame($position, ChapterLine::positionOf($text));
    }
}
