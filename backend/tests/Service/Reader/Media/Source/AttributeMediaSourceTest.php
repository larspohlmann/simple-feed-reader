<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Source;

use App\Service\Reader\Media\DurableMediaUrl;
use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\MediaRelevance;
use App\Service\Reader\Media\MediaUrlKind;
use App\Service\Reader\Media\Provider\SoundCloudEmbedProvider;
use App\Service\Reader\Media\Provider\YouTubeEmbedProvider;
use App\Service\Reader\Media\Source\AttributeMediaSource;
use PHPUnit\Framework\TestCase;

final class AttributeMediaSourceTest extends TestCase
{
    private const string PROSE =
        'The paragraph the player followed on the source page, long enough to be prose.';

    private AttributeMediaSource $source;

    protected function setUp(): void
    {
        $providers = new EmbedProviders([new YouTubeEmbedProvider(), new SoundCloudEmbedProvider()]);
        $this->source = new AttributeMediaSource(
            new MediaUrlKind(new DurableMediaUrl(), $providers),
            new MediaRelevance(),
        );
    }

    /** Deutschlandradio's data-audio-src, reached without naming Deutschlandradio. */
    public function testFindsAMediaUrlInAnyAttribute(): void
    {
        $html = '<body><div data-audio-src="https://x.test/bildung-episode.mp3"></div></body>';

        $found = $this->source->find($html, 'https://x.test/bildung-100.html');

        self::assertCount(1, $found);
        self::assertSame(MediaKind::Audio, $found[0]->kind);
    }

    /** ARD keeps its renditions in a data-v attribute, with the poster in og:image. */
    public function testFindsAVideoInAnAttributeAndTakesTheOgImagePoster(): void
    {
        $html = '<html><head><meta property="og:image" content="https://x.test/p.jpg"></head>'
            . '<body><div data-v="https://x.test/webxxl.mp4"></div></body></html>';

        $found = $this->source->find($html, 'https://x.test/a.html');

        self::assertCount(1, $found);
        self::assertSame('https://x.test/p.jpg', $found[0]->posterUrl);
    }

    /** D5: a video with no og:image poster would rot into a dead frame, so it is dropped. */
    public function testDropsAVideoWhenThePageHasNoOgImage(): void
    {
        $html = '<body><div data-v="https://x.test/clip.mp4"></div></body>';

        $found = $this->source->find($html, 'https://x.test/a.html');

        self::assertSame([], $found);
    }

    /** The live stream sits beside the episode on the same page; it must lose. */
    public function testExcludesALiveStreamBesideAnEpisode(): void
    {
        $html = '<body><div data-x="https://st01.sslstream.dlf.de/dlf/01/128/mp3/stream.mp3"></div>'
            . '<div data-y="https://x.test/bildung-episode.mp3"></div></body>';

        $found = $this->source->find($html, 'https://x.test/bildung-100.html');

        self::assertCount(1, $found);
        self::assertStringContainsString('bildung-episode', $found[0]->url);
    }

    /** Ranked, not first-come: the teaser appears first in source order and loses. */
    public function testPrefersTheFileWhoseNameEchoesTheSlug(): void
    {
        $html = '<body><div data-a="https://x.test/teaser-other.mp3"></div>'
            . '<div data-b="https://x.test/bildung-episode.mp3"></div></body>';

        $found = $this->source->find($html, 'https://x.test/bildung-100.html');

        self::assertStringContainsString('bildung-episode', $found[0]->url);
    }

    /** The durable url has one home: a query-bearing attribute url is stripped before it is cached. */
    public function testStripsAQueryFromTheAttributeUrlBeforeEmitting(): void
    {
        $html = '<body><div data-audio-src="https://x.test/bildung-episode.mp3?utm=x"></div></body>';

        $found = $this->source->find($html, 'https://x.test/bildung-100.html');

        self::assertCount(1, $found);
        self::assertStringNotContainsString('?', $found[0]->url);
        self::assertSame('https://x.test/bildung-episode.mp3', $found[0]->url);
    }

    public function testFindsTheEpisodeInTheCapturedDeutschlandradioPage(): void
    {
        $html = file_get_contents(__DIR__ . '/../../../../Fixtures/reader/media/deutschlandradio-audio.html');
        self::assertIsString($html);

        $found = $this->source->find($html, 'https://www.deutschlandfunkkultur.de/bildung-100.html');

        self::assertNotSame([], $found);
        self::assertStringContainsString('.mp3', $found[0]->url);
        self::assertStringNotContainsString('sslstream', $found[0]->url);
        self::assertStringContainsString('bildung', $found[0]->url);
    }

    public function testFindsTheVideoInTheCapturedArdPage(): void
    {
        $html = file_get_contents(__DIR__ . '/../../../../Fixtures/reader/media/ard-video.html');
        self::assertIsString($html);

        $found = $this->source->find($html, 'https://www.tagesschau.de/ausland/beispiel-100.html');

        self::assertNotSame([], $found);
        self::assertStringContainsString('.mp4', $found[0]->url);
        self::assertNotNull($found[0]->posterUrl);
    }

    public function testNamesTheProseBlockTheChosenPlayerFollows(): void
    {
        // The first (teaser) player sits earlier; the anchor belongs to the winner.
        $html = '<body><p>Teaser line, long enough to be a prose block on its own terms.</p>'
            . '<div data-audio-src="https://x.test/teaser-episode.mp3"></div>'
            . '<p>' . self::PROSE . '</p>'
            . '<div data-audio-src="https://x.test/bildung-episode.mp3"></div></body>';

        $found = $this->source->find($html, 'https://x.test/bildung-100.html');

        self::assertCount(1, $found);
        self::assertSame('https://x.test/bildung-episode.mp3', $found[0]->url);
        self::assertSame(self::PROSE, $found[0]->precedingText);
    }

    public function testAnchorsARepeatedUrlWhereItFirstAppears(): void
    {
        // ARD lists the same rendition in the player and again in a download menu.
        $html = '<body><p>' . self::PROSE . '</p>'
            . '<div data-audio-src="https://x.test/bildung-episode.mp3"></div>'
            . '<p>A download menu paragraph, long enough to be a prose block of its own.</p>'
            . '<a data-download="https://x.test/bildung-episode.mp3">Download</a></body>';

        $found = $this->source->find($html, 'https://x.test/bildung-100.html');

        self::assertSame(self::PROSE, $found[0]->precedingText);
    }

    public function testYieldsAudioAndVideoFromTheSamePage(): void
    {
        $html = '<html lang="de"><head><meta property="og:image" content="https://x.test/p.jpg"></head>'
            . '<body><div data-audio-src="https://x.test/bildung-episode.mp3"></div>'
            . '<div data-v="https://x.test/bildung-episode.mp4"></div></body></html>';

        $found = $this->source->find($html, 'https://x.test/bildung-100.html');

        self::assertCount(2, $found);
    }

    public function testSkipsAPlayerInsideAnAside(): void
    {
        $html = '<body><aside><div data-audio-src="https://x.test/bildung-episode.mp3"></div></aside></body>';

        self::assertSame([], $this->source->find($html, 'https://x.test/bildung-100.html'));
    }
}
