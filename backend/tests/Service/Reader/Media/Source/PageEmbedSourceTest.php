<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Source;

use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\Provider\SoundCloudEmbedProvider;
use App\Service\Reader\Media\Provider\YouTubeEmbedProvider;
use App\Service\Reader\Media\Source\PageEmbedSource;
use PHPUnit\Framework\TestCase;

final class PageEmbedSourceTest extends TestCase
{
    private const string PROSE =
        'The paragraph the player followed on the source page, long enough to be prose.';

    private PageEmbedSource $source;

    protected function setUp(): void
    {
        $this->source = new PageEmbedSource(
            new EmbedProviders([new YouTubeEmbedProvider(), new SoundCloudEmbedProvider()])
        );
    }

    /** 5 Magazine: readability removes this iframe before the body cleaner runs. */
    public function testFindsASoundCloudPlayerOnThePage(): void
    {
        $html = '<html><body><iframe src="https://w.soundcloud.com/player/'
            . '?url=https%3A//api.soundcloud.com/tracks/2370150908&amp;auto_play=true"></iframe></body></html>';

        $found = $this->source->find($html, 'https://5mag.net/audio/dj-set/');

        self::assertCount(1, $found);
        self::assertSame(MediaKind::Embed, $found[0]->kind);
        self::assertStringNotContainsString('auto_play', $found[0]->url);
        self::assertSame('Listen on SoundCloud', $found[0]->label);
    }

    public function testIgnoresAnIframeNoProviderClaims(): void
    {
        $html = '<html><body><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-1"></iframe></body></html>';

        self::assertSame([], $this->source->find($html, 'https://example.test/x'));
    }

    public function testKeepsSourceOrderAndDeduplicates(): void
    {
        $html = '<html><body>'
            . '<iframe src="https://www.youtube.com/embed/aaaaaaaaaaa"></iframe>'
            . '<iframe src="https://www.youtube.com/embed/bbbbbbbbbbb"></iframe>'
            . '<iframe src="https://www.youtube-nocookie.com/embed/aaaaaaaaaaa"></iframe>'
            . '</body></html>';

        $found = $this->source->find($html, 'https://example.test/x');

        self::assertCount(2, $found);
        self::assertStringEndsWith('aaaaaaaaaaa', $found[0]->url);
        self::assertStringEndsWith('bbbbbbbbbbb', $found[1]->url);
    }

    public function testFindsAnEmbedOnAnyElementWithASrcAttribute(): void
    {
        $html = '<html><body><a-iframe src="https://www.youtube.com/embed/ccccccccccc"></a-iframe></body></html>';

        $found = $this->source->find($html, 'https://heise.de/x');

        self::assertCount(1, $found);
        self::assertStringEndsWith('ccccccccccc', $found[0]->url);
    }

    public function testResolvesAProtocolRelativeSource(): void
    {
        $html = '<html><body><iframe src="//www.youtube-nocookie.com/embed/M1j_uRqKMKI"></iframe></body></html>';

        $found = $this->source->find($html, 'https://heise.de/x');

        self::assertCount(1, $found);
        self::assertSame('https://www.youtube-nocookie.com/embed/M1j_uRqKMKI', $found[0]->url);
    }

    public function testRecoversTheYouTubeEmbedFromTheHeiseFixture(): void
    {
        $html = file_get_contents(__DIR__ . '/../../../../Fixtures/reader/media/heise-video.html');
        self::assertIsString($html);

        $pageUrl = 'https://www.heise.de/hintergrund/Was-ich-ueber-lokale-KI-gelernt-habe.html';
        $found = $this->source->find($html, $pageUrl);

        $youtube = array_values(array_filter(
            $found,
            static fn ($candidate): bool => str_contains($candidate->url, 'M1j_uRqKMKI')
        ));

        self::assertCount(1, $youtube);
        self::assertSame('https://www.youtube-nocookie.com/embed/M1j_uRqKMKI', $youtube[0]->url);
    }

    public function testNamesTheProseBlockTheEmbedFollows(): void
    {
        $html = '<body><p>' . self::PROSE . '</p>'
            . '<iframe src="https://www.youtube.com/embed/aaaaaaaaaaa"></iframe></body>';

        $found = $this->source->find($html, 'https://example.test/x');

        self::assertSame(self::PROSE, $found[0]->precedingText);
    }

    public function testAnchorsARepeatedEmbedWhereItFirstAppears(): void
    {
        $html = '<body><p>' . self::PROSE . '</p>'
            . '<iframe src="https://www.youtube.com/embed/aaaaaaaaaaa"></iframe>'
            . '<p>A related-videos paragraph, long enough to be a prose block of its own.</p>'
            . '<iframe src="https://www.youtube-nocookie.com/embed/aaaaaaaaaaa"></iframe></body>';

        $found = $this->source->find($html, 'https://example.test/x');

        self::assertCount(1, $found);
        self::assertSame(self::PROSE, $found[0]->precedingText);
    }

    public function testSkipsAnEmbedInsideAnAside(): void
    {
        $html = '<body><aside><iframe src="https://www.youtube.com/embed/aaaaaaaaaaa"></iframe></aside>'
            . '<iframe src="https://www.youtube.com/embed/bbbbbbbbbbb"></iframe></body>';

        $found = $this->source->find($html, 'https://example.test/x');

        self::assertCount(1, $found);
        self::assertStringContainsString('bbbbbbbbbbb', $found[0]->url);
    }
}
