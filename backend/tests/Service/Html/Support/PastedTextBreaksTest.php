<?php

declare(strict_types=1);

namespace App\Tests\Service\Html\Support;

use App\Service\Html\Support\HtmlDocumentParser;
use App\Service\Html\Support\PastedTextBreaks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PastedTextBreaksTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function rewrittenBodies(): iterable
    {
        yield 'a blank line and a single break' => ["<p>a\n\nb\nc</p>", '<p>a<br><br>b<br>c</p>'];
        yield 'a run of blank lines' => ["<p>a\n\n\n\nb</p>", '<p>a<br><br>b</p>'];
        yield 'windows line endings' => ["<p>a\r\n\r\nb</p>", '<p>a<br><br>b</p>'];
        yield 'lines trimmed, edge whitespace kept' => [
            "<p>\n  a\n\n  b  \n</p>",
            "<p>\n  a<br><br>b  \n</p>",
        ];
        yield 'an image stays, the text breaks' => [
            "<p><img src=\"x\">\n    Intro.\n\nTrack:\n1. One</p>",
            "<p><img src=\"x\">\n    Intro.<br><br>Track:<br>1. One</p>",
        ];
        yield 'an entity is kept escaped once' => [
            "<p>Fish &amp; Chips\n\nPeas</p>",
            '<p>Fish &amp; Chips<br><br>Peas</p>',
        ];
    }

    #[DataProvider('rewrittenBodies')]
    public function testPastedTextGetsItsBreaksBack(string $html, string $expected): void
    {
        self::assertSame($expected, PastedTextBreaks::inHtml($html));
    }

    /** @return iterable<string, array{string}> */
    public static function bodiesLeftAlone(): iterable
    {
        yield 'single newlines only' => ["<p>hard\nwrapped</p>"];
        yield 'blank lines only at the edges' => ["<p>\n\n  text\n\n</p>"];
        yield 'outside a paragraph' => ["<div>a\n\nb</div>"];
        yield 'top-level text' => ["a\n\nb"];
        yield 'inside code' => ["<p><code>a\n\nb</code></p>"];
        yield 'no newline at all' => ['<p>a  b <b>c</b></p>'];
    }

    #[DataProvider('bodiesLeftAlone')]
    public function testOtherTextComesBackExactly(string $html): void
    {
        self::assertSame($html, PastedTextBreaks::inHtml($html));
    }

    public function testRestoreInRewritesAParsedDocument(): void
    {
        $document = HtmlDocumentParser::parse("<html><body><p>a\n\nb</p></body></html>");

        PastedTextBreaks::restoreIn($document);

        self::assertSame('<p>a<br><br>b</p>', $document->body?->innerHTML);
    }
}
