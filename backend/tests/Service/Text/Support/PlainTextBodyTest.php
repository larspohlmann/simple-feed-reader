<?php

declare(strict_types=1);

namespace App\Tests\Service\Text\Support;

use App\Service\Text\Support\PlainTextBody;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlainTextBodyTest extends TestCase
{
    public function testNullStaysNull(): void
    {
        self::assertNull(PlainTextBody::asHtml(null));
    }

    /** @return iterable<string, array{string}> */
    public static function bodiesLeftAlone(): iterable
    {
        yield 'an opening tag' => ["<p>one</p>\ntwo"];
        yield 'a self-closing tag' => ["one<br/>\ntwo"];
        yield 'a closing tag alone' => ["one</b>\ntwo"];
        yield 'a comment' => ["one<!-- x -->\ntwo"];
        yield 'a single line' => ['Just one line.'];
    }

    #[DataProvider('bodiesLeftAlone')]
    public function testAMarkupOrSingleLineBodyIsLeftAlone(string $body): void
    {
        self::assertSame($body, PlainTextBody::asHtml($body));
    }

    public function testLineBreaksBecomeBreaksInOneParagraph(): void
    {
        self::assertSame(
            '<p>Track list :<br>1.Idaishoy<br>2.Silver Galaxy</p>',
            PlainTextBody::asHtml("Track list :\n1.Idaishoy\n2.Silver Galaxy"),
        );
    }

    public function testBlankLinesSeparateParagraphs(): void
    {
        self::assertSame('<p>one</p><p>two<br>three</p>', PlainTextBody::asHtml("one\n\ntwo\nthree"));
    }

    public function testWindowsAndOldMacLineEndingsAreLineBreaks(): void
    {
        self::assertSame('<p>one<br>two<br>three</p>', PlainTextBody::asHtml("one\r\ntwo\rthree"));
    }

    public function testLinesAreTrimmedAndBlankRunsCollapse(): void
    {
        self::assertSame('<p>one</p><p>two</p>', PlainTextBody::asHtml("  one  \n \n\n\t\ntwo \n"));
    }

    public function testLeadingAndTrailingBlankLinesMakeNoEmptyParagraph(): void
    {
        self::assertSame('<p>one</p><p>two</p>', PlainTextBody::asHtml("\n\none\n\ntwo\n\n"));
    }

    public function testEntitiesAreNotEncodedTwice(): void
    {
        self::assertSame('<p>Fish &amp; Chips<br>peas</p>', PlainTextBody::asHtml("Fish &amp; Chips\npeas"));
    }

    public function testALessThanSignThatOpensNoTagIsText(): void
    {
        self::assertSame('<p>a &lt; b<br>c</p>', PlainTextBody::asHtml("a < b\nc"));
    }
}
