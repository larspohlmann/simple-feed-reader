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
        yield 'old mac line endings' => ["<p>a\r\rb</p>", '<p>a<br><br>b</p>'];
        yield 'lines trimmed, edge whitespace kept' => [
            "<p>\n  a\n\n  b  \n</p>",
            "<p>\n  a<br><br>b  \n</p>",
        ];
        yield 'an image stays, the text breaks' => [
            "<p><img src=\"x\">\n    Intro.\n\nTrack:\n1. One</p>",
            "<p><img src=\"x\">\n    Intro.<br><br>Track:<br>1. One</p>",
        ];
        yield 'a meta charset inside the fragment is not obeyed' => [
            "<meta charset=\"iso-8859-1\"><p>Grüße\n\nZweite</p>",
            '<meta charset="iso-8859-1"><p>Grüße<br><br>Zweite</p>',
        ];
        yield 'leading head content stays in the body' => [
            "<style>p{}</style><p>a\n\nb</p>",
            '<style>p{}</style><p>a<br><br>b</p>',
        ];
        yield 'every line of a paragraph split by links' => [
            "<p>Tracklist:\n1. <a href=\"x\">Artist</a> - One\n2. <a href=\"y\">Artist</a> - Two\n\nThanks</p>",
            '<p>Tracklist:<br>1. <a href="x">Artist</a> - One<br>2. <a href="y">Artist</a> - Two<br><br>Thanks</p>',
        ];
        yield 'whitespace between two elements stays' => [
            "<p>a\n\nb <i>x</i>\n<i>y</i></p>",
            "<p>a<br><br>b <i>x</i>\n<i>y</i></p>",
        ];
        yield 'inside an inline element' => ["<p><strong>a\n\nb</strong></p>", '<p><strong>a<br><br>b</strong></p>'];
        yield 'a blank line spread over two nodes' => ["<p>a\n<!--x-->\nb\nc</p>", "<p>a\n<!--x-->\nb<br>c</p>"];
        yield 'code in a pasted paragraph keeps its newlines' => [
            "<p>a\n\nb <code>x\ny</code></p>",
            "<p>a<br><br>b <code>x\ny</code></p>",
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
        yield 'single newlines spread over several nodes' => ["<p>a\n<b>b\nc</b>\n<i>d</i></p>"];
        yield 'a blank line only between paragraphs' => ["<p class=x>a</p>\n\n<p>b<br/></p>"];
        yield 'a blank line only between divs' => ["<div><span>a</span>\n\n<span>b</span></div>"];
        yield 'a blank line only in verbatim text' => ["<p>a <kbd>x\n\ny</kbd> <samp>u\n\nv</samp></p>"];
        yield 'single newlines with windows line endings' => ["<p class=x>a\r\nb</p>"];
    }

    #[DataProvider('bodiesLeftAlone')]
    public function testOtherTextComesBackExactly(string $html): void
    {
        self::assertSame($html, PastedTextBreaks::inHtml($html));
    }

    public function testRestoreInRewritesAParsedDocument(): void
    {
        $document = HtmlDocumentParser::parse("<html><body><p>a\n\nb</p></body></html>");

        $restored = PastedTextBreaks::restoreIn($document);

        self::assertSame('<p>a<br><br>b</p>', $document->body?->innerHTML);
        self::assertSame(1, $restored);
    }
}
