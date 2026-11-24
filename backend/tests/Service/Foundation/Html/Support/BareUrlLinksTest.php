<?php

declare(strict_types=1);

namespace App\Tests\Service\Foundation\Html\Support;

use App\Service\Foundation\Html\Support\BareUrlLinks;
use App\Service\Foundation\Html\Support\HtmlDocumentParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BareUrlLinksTest extends TestCase
{
    public function testLinksABareUrlAndLeavesTrailingPunctuationOutside(): void
    {
        self::assertSame(
            '<p>See <a href="https://example.org/a">https://example.org/a</a>.</p>',
            BareUrlLinks::linkHtml('<p>See https://example.org/a.</p>'),
        );
    }

    public function testHtmlWithoutABareUrlComesBackExactlyAsGiven(): void
    {
        self::assertSame('<p>open <b>bold', BareUrlLinks::linkHtml('<p>open <b>bold'));
    }

    public function testLinksADocumentParsedByTheCallerAndLeavesItLinked(): void
    {
        $html = '<p>https://example.org/a and http://example.org/b</p>';
        $document = HtmlDocumentParser::parseFragment($html);

        $linked = BareUrlLinks::linkIn($document, $html);

        self::assertSame(2, substr_count($linked, '<a href='));
        self::assertCount(2, $document->querySelectorAll('a'));
    }

    #[DataProvider('unlinkable')]
    public function testLeavesUrlsInLinksAndCodeAlone(string $html): void
    {
        self::assertSame($html, BareUrlLinks::linkHtml($html));
    }

    /** @return iterable<string, array{string}> */
    public static function unlinkable(): iterable
    {
        yield 'anchor' => ['<a href="https://example.org">https://example.org</a>'];
        yield 'code' => ['<code>https://example.org</code>'];
        yield 'pre' => ['<pre>https://example.org</pre>'];
    }
}
