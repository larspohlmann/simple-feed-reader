<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Teaser;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Media\DurableMediaUrl;
use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\MediaUrlKind;
use App\Service\Reader\Media\Teaser\TeaserPlayerScanner;
use Dom\HTMLDocument;
use PHPUnit\Framework\TestCase;

final class TeaserPlayerScannerTest extends TestCase
{
    private TeaserPlayerScanner $scanner;

    protected function setUp(): void
    {
        $this->scanner = new TeaserPlayerScanner(new MediaUrlKind(new DurableMediaUrl(), new EmbedProviders([])));
    }

    private function document(string $html): HTMLDocument
    {
        $document = HtmlDocumentParser::parseOrNull($html);
        self::assertNotNull($document);

        return $document;
    }

    /** A block that pairs a player with its own still, a headline and a link is an inline media teaser. */
    public function testReadsThePlayerItsStillCaptionAndLink(): void
    {
        $html = '<body><div class="block">'
            . '<picture><img src="https://x.test/still.jpg"></picture>'
            . '<div data-v="https://x.test/clip.webxxl.mp4"></div>'
            . '<a href="https://x.test/related.html">  Kicker —  The headline  </a>'
            . '</div></body>';

        $found = $this->scanner->scan($this->document($html), 'https://x.test/article-100.html');

        self::assertCount(1, $found);
        self::assertSame(MediaKind::Video, $found[0]->kind);
        self::assertSame('https://x.test/clip.webxxl.mp4', $found[0]->mediaUrl);
        self::assertSame('https://x.test/still.jpg', $found[0]->posterUrl);
        self::assertSame('https://x.test/related.html', $found[0]->linkUrl);
        // Whitespace runs are collapsed and the ends trimmed.
        self::assertSame('Kicker — The headline', $found[0]->caption);
    }

    /** The still must be an https image; a scheme-relative or http one is not taken. */
    public function testIgnoresANonHttpsStill(): void
    {
        $html = '<body><div class="block">'
            . '<picture><img src="http://x.test/still.jpg"></picture>'
            . '<div data-v="https://x.test/clip.mp4"></div>'
            . '</div></body>';

        self::assertSame([], $this->scanner->scan($this->document($html), 'https://x.test/a-100.html'));
    }

    /** The same player URL from two blocks is one teaser, not two. */
    public function testDeduplicatesTheSamePlayerUrlAcrossBlocks(): void
    {
        $block = '<div class="block"><picture><img src="https://x.test/s.jpg"></picture>'
            . '<div data-v="https://x.test/clip.mp4"></div></div>';
        $document = $this->document('<body>' . $block . $block . '</body>');

        self::assertCount(1, $this->scanner->scan($document, 'https://x.test/a-100.html'));
    }

    /** The still is looked for close to the player, not across the whole page. */
    public function testDoesNotReachBeyondThePlayersNearAncestorsForAStill(): void
    {
        // The still sits at body level; the player is six wrappers deep, past the near-ancestor window.
        $html = '<body><img src="https://x.test/far.jpg">'
            . '<div><div><div><div><div><div>'
            . '<div data-v="https://x.test/clip.mp4"></div>'
            . '</div></div></div></div></div></div></body>';

        self::assertSame([], $this->scanner->scan($this->document($html), 'https://x.test/a-100.html'));
    }

    /** A path-relative href resolves against the page directory, not dropped. */
    public function testResolvesAPathRelativeLinkAgainstThePage(): void
    {
        $html = '<body><div class="block">'
            . '<picture><img src="https://x.test/s.jpg"></picture>'
            . '<div data-v="https://x.test/clip.mp4"></div>'
            . '<a href="deeper/related.html">Headline</a>'
            . '</div></body>';

        $found = $this->scanner->scan($this->document($html), 'https://news.test/section/a-100.html');

        self::assertSame('https://news.test/section/deeper/related.html', $found[0]->linkUrl);
    }

    /** Audio teasers count too — the pflege page is four of them. */
    public function testReadsAnAudioTeaser(): void
    {
        $html = '<body><div class="block">'
            . '<picture><img src="https://x.test/still.jpg"></picture>'
            . '<div data-audio-src="https://x.test/episode.mp3"></div>'
            . '<a href="https://x.test/related.html">Headline</a>'
            . '</div></body>';

        $found = $this->scanner->scan($this->document($html), 'https://x.test/article-100.html');

        self::assertCount(1, $found);
        self::assertSame(MediaKind::Audio, $found[0]->kind);
    }

    /** A player without its own still is the article's own or a bare stream, not a teaser to reconstruct. */
    public function testSkipsAPlayerWithoutAStill(): void
    {
        $html = '<body><div class="block"><div data-v="https://x.test/clip.mp4"></div></div></body>';

        self::assertSame([], $this->scanner->scan($this->document($html), 'https://x.test/article-100.html'));
    }

    /** A player in page chrome (a related-links rail) is not the article's content. */
    public function testSkipsAPlayerInFurniture(): void
    {
        $html = '<body><aside><div class="block">'
            . '<picture><img src="https://x.test/still.jpg"></picture>'
            . '<div data-v="https://x.test/clip.mp4"></div>'
            . '</div></aside></body>';

        self::assertSame([], $this->scanner->scan($this->document($html), 'https://x.test/article-100.html'));
    }

    /** The sanitizer drops scheme-less links, so a root-relative href is resolved against the page. */
    public function testResolvesARootRelativeLinkAgainstThePage(): void
    {
        $html = '<body><div class="block">'
            . '<picture><img src="https://x.test/still.jpg"></picture>'
            . '<div data-v="https://x.test/clip.mp4"></div>'
            . '<a href="/inland/related-100.html">Headline</a>'
            . '</div></body>';

        $found = $this->scanner->scan($this->document($html), 'https://news.test/article-100.html');

        self::assertSame('https://news.test/inland/related-100.html', $found[0]->linkUrl);
    }

    /** One player's renditions stay one teaser, not one per rendition URL. */
    public function testCollapsesTheRenditionsOfOnePlayer(): void
    {
        $html = '<body><div class="block">'
            . '<picture><img src="https://x.test/still.jpg"></picture>'
            . '<div data-v="https://x.test/clip.webs.mp4 https://x.test/clip.webxxl.mp4"></div>'
            . '</div></body>';

        $found = $this->scanner->scan($this->document($html), 'https://x.test/article-100.html');

        self::assertCount(1, $found);
    }
}
