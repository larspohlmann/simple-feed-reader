<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Media\InBodyEmbedRewriter;
use App\Service\Reader\Media\MediaMarkup;
use App\Service\Reader\Media\Provider\SoundCloudEmbedProvider;
use App\Service\Reader\Media\Provider\YouTubeEmbedProvider;
use PHPUnit\Framework\TestCase;

final class InBodyEmbedRewriterTest extends TestCase
{
    private InBodyEmbedRewriter $rewriter;

    protected function setUp(): void
    {
        $this->rewriter = new InBodyEmbedRewriter(
            new EmbedProviders([new YouTubeEmbedProvider(), new SoundCloudEmbedProvider()]),
            new MediaMarkup(),
        );
    }

    private function rewrite(string $html): string
    {
        $document = HtmlDocumentParser::parseOrNull($html);
        self::assertNotNull($document);
        $this->rewriter->rewriteIn($document);

        return $document->saveHtml();
    }

    /** The OZORA shape: a heading, then the embed, ten times over. */
    public function testKeepsEachEmbedAtItsHeadingPosition(): void
    {
        $html = '<body><h3>One</h3><div><iframe src="https://www.youtube.com/embed/aaaaaaaaaaa?si=x"></iframe></div>'
            . '<h3>Two</h3><div><iframe src="https://www.youtube.com/embed/bbbbbbbbbbb"></iframe></div></body>';

        $out = $this->rewrite($html);

        self::assertStringContainsString('<h3>One</h3>', $out);
        self::assertStringContainsString('youtube-nocookie.com/embed/aaaaaaaaaaa', $out);
        self::assertStringContainsString('youtube-nocookie.com/embed/bbbbbbbbbbb', $out);
        self::assertLessThan(
            strpos($out, 'bbbbbbbbbbb'),
            strpos($out, 'aaaaaaaaaaa'),
            'embeds must keep source order'
        );
        self::assertStringNotContainsString('<iframe', $out);
        self::assertStringNotContainsString('si=x', $out);
    }

    public function testAYouTubeEmbedBecomesAPosterLink(): void
    {
        $out = $this->rewrite('<body><iframe src="https://www.youtube.com/embed/aaaaaaaaaaa"></iframe></body>');

        self::assertStringContainsString('href="https://www.youtube-nocookie.com/embed/aaaaaaaaaaa"', $out);
        self::assertStringContainsString('i.ytimg.com/vi/aaaaaaaaaaa/hqdefault.jpg', $out);
    }

    /** No cheap poster, so the link carries text a reader can act on. */
    public function testASoundCloudEmbedBecomesATextLink(): void
    {
        $out = $this->rewrite('<body><iframe src="https://w.soundcloud.com/player/'
            . '?url=https%3A//api.soundcloud.com/tracks/2370150908&amp;auto_play=true"></iframe></body>');

        self::assertStringContainsString('Listen on SoundCloud', $out);
        self::assertStringNotContainsString('auto_play', $out);
    }

    public function testAnUnknownIframeIsLeftForTheSanitizer(): void
    {
        $html = '<body><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-1"></iframe></body>';

        self::assertStringContainsString('googletagmanager', $this->rewrite($html));
    }

    public function testReportsWhetherItActed(): void
    {
        $none = HtmlDocumentParser::parseOrNull('<body><p>text</p></body>');
        self::assertNotNull($none);
        self::assertFalse($this->rewriter->rewriteIn($none));

        $one = HtmlDocumentParser::parseOrNull('<body><iframe src="https://youtu.be/aaaaaaaaaaa"></iframe></body>');
        self::assertNotNull($one);
        self::assertTrue($this->rewriter->rewriteIn($one));
    }

    /** Do not reuse #627's alt text: its CSS paints a play badge on that string. */
    public function testDoesNotReuseTheSubstackPlaceholderAltText(): void
    {
        $out = $this->rewrite('<body><iframe src="https://www.youtube.com/embed/aaaaaaaaaaa"></iframe></body>');

        self::assertStringNotContainsString('Video — open the original article to watch', $out);
    }
}
