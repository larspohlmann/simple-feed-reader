<?php

declare(strict_types=1);

namespace App\Tests\Service\Text\Support;

use App\Service\Text\Support\PlainTextBody;
use PHPUnit\Framework\TestCase;

final class PlainTextBodyTest extends TestCase
{
    public function testNullStaysNull(): void
    {
        self::assertNull(PlainTextBody::asHtml(null));
    }

    public function testAMarkupBodyIsLeftAlone(): void
    {
        self::assertSame("<p>one</p>\ntwo", PlainTextBody::asHtml("<p>one</p>\ntwo"));
    }

    public function testASelfClosingTagCountsAsMarkup(): void
    {
        self::assertSame("one<br/>\ntwo", PlainTextBody::asHtml("one<br/>\ntwo"));
    }

    public function testAClosingTagAloneCountsAsMarkup(): void
    {
        self::assertSame("one</b>\ntwo", PlainTextBody::asHtml("one</b>\ntwo"));
    }

    public function testACommentCountsAsMarkup(): void
    {
        self::assertSame("one<!-- x -->\ntwo", PlainTextBody::asHtml("one<!-- x -->\ntwo"));
    }

    public function testASingleLineIsLeftAlone(): void
    {
        self::assertSame('Just one line.', PlainTextBody::asHtml('Just one line.'));
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

    public function testEntitiesAreNotEncodedTwice(): void
    {
        self::assertSame('<p>Fish &amp; Chips<br>peas</p>', PlainTextBody::asHtml("Fish &amp; Chips\npeas"));
    }

    public function testALessThanSignThatOpensNoTagIsText(): void
    {
        self::assertSame('<p>a &lt; b<br>c</p>', PlainTextBody::asHtml("a < b\nc"));
    }
}
