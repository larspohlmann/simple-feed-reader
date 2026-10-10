<?php

declare(strict_types=1);

namespace App\Tests\Service\Text\Support;

use App\Service\Text\Support\LinkedPlainText;
use PHPUnit\Framework\TestCase;

final class LinkedPlainTextTest extends TestCase
{
    public function testBlankLinesSplitParagraphsAndSingleBreaksBecomeBr(): void
    {
        self::assertSame(
            '<p>First line<br>second line</p><p>Next paragraph</p>',
            LinkedPlainText::asHtml("First line\r\nsecond line\n\n  \nNext paragraph\n"),
        );
    }

    public function testMarkupIsEscapedNotInterpreted(): void
    {
        self::assertSame(
            '<p>&lt;b&gt;Tom &amp; Jerry&lt;/b&gt; &quot;quoted&quot;</p>',
            LinkedPlainText::asHtml('<b>Tom & Jerry</b> "quoted"'),
        );
    }

    public function testAnInvalidByteIsReplacedRatherThanBlankingTheText(): void
    {
        self::assertSame("<p>Caf\u{FFFD} &amp; cr\u{E8}me</p>", LinkedPlainText::asHtml("Caf\xE9 & cr\u{E8}me"));
    }

    public function testBareUrlsBecomeLinksWithoutTrailingPunctuation(): void
    {
        self::assertSame(
            '<p>Shop: <a href="https://example.com/a?b=1&amp;c=2">https://example.com/a?b=1&amp;c=2</a>. '
            . '(<a href="http://x.example/">http://x.example/</a>)</p>',
            LinkedPlainText::asHtml('Shop: https://example.com/a?b=1&c=2. (http://x.example/)'),
        );
    }

    public function testBlankTextIsNull(): void
    {
        self::assertNull(LinkedPlainText::asHtml(" \n\n "));
    }
}
