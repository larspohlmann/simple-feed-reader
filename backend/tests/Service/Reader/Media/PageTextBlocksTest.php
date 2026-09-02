<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Media\PageTextBlocks;
use Dom\HTMLDocument;
use PHPUnit\Framework\TestCase;

final class PageTextBlocksTest extends TestCase
{
    private const string LONG =
        'A paragraph long enough to count as prose the reader keeps, well past forty characters.';
    private const string OTHER =
        'Another paragraph long enough to count as prose, also comfortably past forty characters.';

    private function document(string $html): HTMLDocument
    {
        $document = HtmlDocumentParser::parseOrNull($html);
        self::assertNotNull($document);

        return $document;
    }

    private function before(string $html, string $selector): ?string
    {
        $document = $this->document($html);
        $element = $document->querySelector($selector);
        self::assertNotNull($element);

        return PageTextBlocks::fromDocument($document)->before($element);
    }

    public function testNamesTheNearestPrecedingTextBlock(): void
    {
        $html = '<body><p>' . self::OTHER . '</p><p>' . self::LONG . '</p><div id="player"></div></body>';

        self::assertSame(self::LONG, $this->before($html, '#player'));
    }

    public function testCollapsesWhitespaceInsideTheBlock(): void
    {
        $html = '<body><p>  A paragraph long enough   to count as prose the reader keeps,' . "\n"
            . ' well past forty characters. </p><div id="player"></div></body>';

        self::assertSame(self::LONG, $this->before($html, '#player'));
    }

    public function testSkipsAShortBlockLikeADateline(): void
    {
        $html = '<body><p>' . self::LONG . '</p><p>Stand: 01.09.2026 16:53 Uhr</p><div id="player"></div></body>';

        self::assertSame(self::LONG, $this->before($html, '#player'));
    }

    public function testNeverNamesABlockThatContainsTheElement(): void
    {
        $html = '<body><p>' . self::LONG . ' <span id="player"></span></p></body>';

        self::assertNull($this->before($html, '#player'));
    }

    public function testIgnoresBlocksThatFollowTheElement(): void
    {
        $html = '<body><div id="player"></div><p>' . self::LONG . '</p></body>';

        self::assertNull($this->before($html, '#player'));
    }

    public function testReadsScriptsInTheBodyButNothingPrecedesTheHead(): void
    {
        $html = '<html lang="en"><head><script id="head"></script></head><body><p>' . self::LONG . '</p>'
            . '<div><script id="inline"></script></div></body></html>';

        self::assertNull($this->before($html, '#head'));
        self::assertSame(self::LONG, $this->before($html, '#inline'));
    }

    public function testFortyCharactersIsLongEnough(): void
    {
        $exactlyForty = 'Forty characters of text, no more no les';
        self::assertSame(40, mb_strlen($exactlyForty));
        $html = '<body><p>' . self::OTHER . '</p><p>' . $exactlyForty . '</p><div id="player"></div></body>';

        self::assertSame($exactlyForty, $this->before($html, '#player'));
    }

    public function testMeasuresLengthInCharactersNotBytes(): void
    {
        // 38 characters, 44 bytes: German prose must not sneak past the bar on umlauts.
        $umlauts = 'Größe, Straße, Übermaß, Äpfel und Öl d';
        self::assertSame(38, mb_strlen($umlauts));
        self::assertGreaterThanOrEqual(40, strlen($umlauts));
        $html = '<body><p>' . self::OTHER . '</p><p>' . $umlauts . '</p><div id="player"></div></body>';

        self::assertSame(self::OTHER, $this->before($html, '#player'));
    }
}
