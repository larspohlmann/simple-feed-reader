<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Source;

use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\Provider\VimeoEmbedProvider;
use App\Service\Reader\Media\Provider\YouTubeEmbedProvider;
use App\Service\Reader\Media\Source\ScriptEmbedSource;
use PHPUnit\Framework\TestCase;

final class ScriptEmbedSourceTest extends TestCase
{
    private function source(): ScriptEmbedSource
    {
        return new ScriptEmbedSource(new EmbedProviders([new YouTubeEmbedProvider(), new VimeoEmbedProvider()]));
    }

    /** @return list<\App\Service\Reader\Media\MediaCandidate> */
    private function find(string $body): array
    {
        return $this->source()->find('<html lang="en"><body>' . $body . '</body></html>', 'https://site.test/a');
    }

    public function testEmbedsAVimeoUrlAScriptVariableCarries(): void
    {
        $found = $this->find('<p>Text.</p><script>var videoData = {"url":"https://vimeo.com/1226652197/"};</script>');

        self::assertCount(1, $found);
        self::assertSame(MediaKind::Embed, $found[0]->kind);
        self::assertSame('https://player.vimeo.com/video/1226652197', $found[0]->url);
        self::assertNull($found[0]->precedingText);
        self::assertSame('Watch on Vimeo', $found[0]->label);
    }

    public function testIgnoresProviderAssetAndPageUrlsThatAreNotVideos(): void
    {
        $found = $this->find(
            '<script>var api = "https://player.vimeo.com/api/player.js";'
            . 'var channel = "https://www.youtube.com/lionsroaronline";</script>'
        );

        self::assertSame([], $found);
    }

    public function testSkipsScriptsInsidePageChrome(): void
    {
        $found = $this->find(
            '<article><p>Body.</p></article>'
            . '<footer><script>var videoData = {"url":"https://vimeo.com/1226652197/"};</script></footer>'
        );

        self::assertSame([], $found);
    }

    public function testCollapsesTheSameVideoNamedByTwoScripts(): void
    {
        $found = $this->find(
            '<script>var videoData = {"url":"https://vimeo.com/1226652197/"};</script>'
            . '<script>var alt = "https://vimeo.com/1226652197";</script>'
        );

        self::assertCount(1, $found);
        self::assertSame('https://player.vimeo.com/video/1226652197', $found[0]->url);
    }

    public function testSkipsJsonLdScriptsSinceLdSourceHandlesThem(): void
    {
        // JSON-LD scripts are processed by JsonLdMediaSource with its own prioritization
        // logic for choosing between contentUrl and embedUrl. ScriptEmbedSource skips them
        // to avoid duplicate embeds that violate that priority.
        $found = $this->find(
            '<script type="application/ld+json">{"@type":"VideoObject",'
            . '"embedUrl":"https://www.youtube.com/embed/aaaaaaaaaa1"}</script>'
        );

        self::assertSame([], $found);
    }

    public function testEmbedsEverySeparateProviderVideoInOneScript(): void
    {
        $found = $this->find(
            '<p>Content.</p>'
            . '<script>var vimeo = "https://vimeo.com/1226652197/"; var youtube = "https://www.youtube.com/watch?v=aaaaaaaaaa1";</script>'
        );

        self::assertCount(2, $found);
        $urls = array_map(static fn($candidate) => $candidate->url, $found);
        self::assertContains('https://player.vimeo.com/video/1226652197', $urls);
        self::assertContains('https://www.youtube-nocookie.com/embed/aaaaaaaaaa1', $urls);
    }

    public function testContinuesLoopAfterSkippingFurnitureScript(): void
    {
        $found = $this->find(
            '<article><p>Article body.</p></article>'
            . '<footer><script>var related = "https://vimeo.com/999999999/";</script></footer>'
            . '<script>var videoData = {"url":"https://vimeo.com/1226652197/"};</script>'
        );

        self::assertCount(1, $found);
        self::assertSame('https://player.vimeo.com/video/1226652197', $found[0]->url);
    }

    public function testContinuesLoopAfterSkippingJsonLdScript(): void
    {
        $found = $this->find(
            '<script type="application/ld+json">{"@type":"VideoObject","embedUrl":"https://example.test/ignore"}</script>'
            . '<script>var videoData = {"url":"https://vimeo.com/1226652197/"};</script>'
        );

        self::assertCount(1, $found);
        self::assertSame('https://player.vimeo.com/video/1226652197', $found[0]->url);
    }
}
