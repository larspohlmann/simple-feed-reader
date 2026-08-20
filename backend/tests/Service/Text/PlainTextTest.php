<?php

declare(strict_types=1);

namespace App\Tests\Service\Text;

use App\Service\Text\PlainText;
use PHPUnit\Framework\TestCase;

final class PlainTextTest extends TestCase
{
    public function testStripsInlineMarkup(): void
    {
        self::assertSame(
            'An Odyssey for Our Own Time',
            PlainText::from('An <em>Odyssey</em> for Our Own Time'),
        );
    }

    public function testDecodesNumericHtmlEntities(): void
    {
        self::assertSame(
            "\u{201C}Datatype\u{201D} is an OpenType variable font",
            PlainText::from('&#8220;Datatype&#8221; is an OpenType variable font'),
        );
    }

    public function testDecodesNamedHtml5Entities(): void
    {
        self::assertSame('Fish & Chips — brilliant', PlainText::from('Fish &amp; Chips &mdash; brilliant'));
    }

    public function testCollapsesRunsOfWhitespace(): void
    {
        self::assertSame('one two three', PlainText::from("one   two\n\tthree"));
    }

    public function testKeepsAlreadyPlainTextUnchanged(): void
    {
        self::assertSame('Rust and C++', PlainText::from('Rust and C++'));
    }

    public function testReturnsNullForNullInput(): void
    {
        self::assertNull(PlainText::from(null));
    }

    public function testReturnsNullWhenNothingPrintableRemains(): void
    {
        self::assertNull(PlainText::from('<em></em>'));
    }

    /**
     * from() has no concept of block-level boundaries — strip_tags()
     * concatenates across them with no separator — which is exactly why
     * fromHtmlBlocks() exists as a distinct method for HTML known to carry
     * block-level structure.
     */
    public function testFromConcatenatesAcrossParagraphsWithNoSeparator(): void
    {
        self::assertSame(
            'oneCloudtwo',
            PlainText::from('<p>oneCloud</p><p>two</p>'),
        );
    }

    public function testFromHtmlBlocksInsertsASpaceAtParagraphBoundaries(): void
    {
        self::assertSame(
            'one two',
            PlainText::fromHtmlBlocks('<p>one</p><p>two</p>'),
        );
    }

    public function testFromHtmlBlocksInsertsASpaceAtLineBreaks(): void
    {
        self::assertSame(
            'one two',
            PlainText::fromHtmlBlocks('one<br>two'),
        );
    }

    public function testFromHtmlBlocksReturnsNullForNullInput(): void
    {
        self::assertNull(PlainText::fromHtmlBlocks(null));
    }
}
