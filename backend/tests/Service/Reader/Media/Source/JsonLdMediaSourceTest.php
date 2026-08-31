<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Source;

use App\Service\Reader\Media\DurableMediaUrl;
use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\MediaUrlKind;
use App\Service\Reader\Media\Provider\SoundCloudEmbedProvider;
use App\Service\Reader\Media\Provider\YouTubeEmbedProvider;
use App\Service\Reader\Media\Source\JsonLdMediaSource;
use PHPUnit\Framework\TestCase;

final class JsonLdMediaSourceTest extends TestCase
{
    private JsonLdMediaSource $source;

    protected function setUp(): void
    {
        $this->source = new JsonLdMediaSource(
            new MediaUrlKind(
                new DurableMediaUrl(),
                new EmbedProviders([new YouTubeEmbedProvider(), new SoundCloudEmbedProvider()]),
            ),
            new EmbedProviders([new YouTubeEmbedProvider(), new SoundCloudEmbedProvider()]),
        );
    }

    public function testTakesContentUrlFromAVideoObject(): void
    {
        $html = '<html><body><script type="application/ld+json">'
            . '{"@type":"VideoObject","contentUrl":"https:\\/\\/www.youtube.com\\/watch?v=M1j_uRqKMKI"}'
            . '</script></body></html>';

        $found = $this->source->find($html, 'https://www.heise.de/news/x.html');

        self::assertCount(1, $found);
        self::assertSame(MediaKind::Embed, $found[0]->kind);
        self::assertSame('https://www.youtube-nocookie.com/embed/M1j_uRqKMKI', $found[0]->url);
    }

    public function testFindsAVideoObjectNestedUnderAnArticle(): void
    {
        $html = '<html><body><script type="application/ld+json">'
            . '{"@type":"NewsArticle","video":{"@type":"VideoObject",'
            . '"contentUrl":"https://x.test/v.mp4","thumbnailUrl":"https://x.test/poster.jpg"}}'
            . '</script></body></html>';

        $found = $this->source->find($html, 'https://x.test/a.html');

        self::assertCount(1, $found);
        self::assertSame(MediaKind::Video, $found[0]->kind);
        self::assertSame('https://x.test/poster.jpg', $found[0]->posterUrl);
    }

    /** D5: a video with no poster rots into a dead frame, so it is dropped, not emitted. */
    public function testDropsAPosterlessVideoObject(): void
    {
        $html = '<html><body><script type="application/ld+json">'
            . '{"@type":"VideoObject","contentUrl":"https://x.test/v.mp4"}'
            . '</script></body></html>';

        self::assertSame([], $this->source->find($html, 'https://x.test/a.html'));
    }

    /** An <audio> element has no poster attribute; a phantom thumbnailUrl must not become one. */
    public function testAnAudioObjectNeverCarriesAPoster(): void
    {
        $html = '<html><body><script type="application/ld+json">'
            . '{"@type":"AudioObject","contentUrl":"https://x.test/a.mp3",'
            . '"thumbnailUrl":"https://x.test/logo.jpg"}'
            . '</script></body></html>';

        $found = $this->source->find($html, 'https://x.test/a.html');

        self::assertCount(1, $found);
        self::assertSame(MediaKind::Audio, $found[0]->kind);
        self::assertNull($found[0]->posterUrl);
    }

    /** 5 Magazine's JSON-LD contentUrls are images; they must not become media. */
    public function testIgnoresAnImageContentUrl(): void
    {
        $html = '<html><body><script type="application/ld+json">'
            . '{"@type":"ImageObject","contentUrl":"https://5mag.net/wp-content/uploads/x.jpg"}'
            . '</script></body></html>';

        self::assertSame([], $this->source->find($html, 'https://5mag.net/audio/x/'));
    }

    public function testIgnoresMalformedJson(): void
    {
        $html = '<html><body><script type="application/ld+json">{not json</script></body></html>';

        self::assertSame([], $this->source->find($html, 'https://x.test/a.html'));
    }

    public function testFindsTheCompanionVideoInTheCapturedHeisePage(): void
    {
        $html = file_get_contents(__DIR__ . '/../../../../Fixtures/reader/media/heise-video.html');
        self::assertIsString($html);

        $found = $this->source->find($html, 'https://www.heise.de/news/x.html');

        self::assertNotSame([], $found);
        self::assertStringContainsString('M1j_uRqKMKI', $found[0]->url);
    }
}
