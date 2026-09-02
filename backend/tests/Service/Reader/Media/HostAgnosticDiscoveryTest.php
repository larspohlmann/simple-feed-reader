<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media;

use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\PageMediaScanner;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Every measured page, through the real container, with no host-keyed source in
 * the set. This is the test the original design lacked.
 */
final class HostAgnosticDiscoveryTest extends KernelTestCase
{
    private function scanner(): PageMediaScanner
    {
        self::bootKernel();
        $scanner = self::getContainer()->get(PageMediaScanner::class);
        self::assertInstanceOf(PageMediaScanner::class, $scanner);

        return $scanner;
    }

    private function fixture(string $name): string
    {
        $html = file_get_contents(__DIR__ . '/../../../Fixtures/reader/media/' . $name);
        self::assertIsString($html);

        return $html;
    }

    public function testDeutschlandradioYieldsItsEpisode(): void
    {
        $media = $this->scanner()->scan(
            $this->fixture('deutschlandradio-audio.html'),
            'https://www.deutschlandfunkkultur.de/bildung-100.html',
        );

        self::assertSame(MediaKind::Audio, $media->candidates[0]->kind);
        self::assertStringNotContainsString('sslstream', $media->candidates[0]->url);
        self::assertStringContainsString('bildung', $media->candidates[0]->url);
    }

    public function testNprYieldsItsSegment(): void
    {
        $media = $this->scanner()->scan(
            $this->fixture('npr-audio.html'),
            'https://www.npr.org/2026/08/30/nx-s1-5948814/launch-nancy-grace-roman-space-telescope-nasa',
        );

        self::assertFalse($media->isEmpty());
        self::assertStringContainsString('telescope', $media->candidates[0]->url);
    }

    public function testArdYieldsAVideoWithAPoster(): void
    {
        $media = $this->scanner()->scan(
            $this->fixture('ard-video.html'),
            'https://www.tagesschau.de/ausland/beispiel-100.html',
        );

        $video = array_values(array_filter(
            $media->candidates,
            static fn ($c): bool => $c->kind === MediaKind::Video,
        ));

        self::assertNotSame([], $video);
        self::assertNotNull($video[0]->posterUrl);
    }

    public function testHeiseYieldsItsCompanionVideo(): void
    {
        $media = $this->scanner()->scan($this->fixture('heise-video.html'), 'https://www.heise.de/news/x.html');

        self::assertStringContainsString('M1j_uRqKMKI', $media->candidates[0]->url);
    }

    public function testFiveMagazineYieldsItsTrack(): void
    {
        $media = $this->scanner()->scan($this->fixture('soundcloud-page.html'), 'https://5mag.net/audio/dj-set/');

        self::assertStringContainsString('soundcloud', $media->candidates[0]->url);
    }

    public function testAnUnseenPublisherYieldsItsMediaWithNoNewCode(): void
    {
        $media = $this->scanner()->scan(
            $this->fixture('unseen-publisher.html'),
            'https://9to5mac.com/2026/08/27/happy-hour-605/',
        );

        self::assertFalse($media->isEmpty());
        self::assertSame(MediaKind::Audio, $media->candidates[0]->kind);
        self::assertStringEndsWith('.mp3', $media->candidates[0]->url);
    }

    /** tagesschau 494183: the related-content sidebar's podcast is not this article's audio. */
    public function testASidebarTeaserDoesNotBecomeTheArticlesMedia(): void
    {
        $media = $this->scanner()->scan(
            $this->fixture('sidebar-teaser.html'),
            'https://www.tagesschau.de/inland/innenpolitik/merz-linke-sachsen-anhalt-100.html',
        );

        $kinds = array_map(static fn ($c): MediaKind => $c->kind, $media->candidates);
        self::assertSame([MediaKind::Video], $kinds);
    }

    /** vice 495401: JSON-LD declares one of four videos; the other three exist only as page embeds inside <noscript>. */
    public function testAPageThatDeclaresOneOfFourVideosYieldsAllFourInPageOrder(): void
    {
        $media = $this->scanner()->scan(
            $this->fixture('multi-embed-page.html'),
            'https://www.vice.com/en/article/4-remixes-from-the-2000s/',
        );

        $urls = array_map(static fn ($c): string => $c->url, $media->candidates);
        self::assertSame([
            'https://www.youtube-nocookie.com/embed/aaaaaaaaaa1',
            'https://www.youtube-nocookie.com/embed/aaaaaaaaaa2',
            'https://www.youtube-nocookie.com/embed/aaaaaaaaaa3',
            'https://www.youtube-nocookie.com/embed/aaaaaaaaaa4',
        ], $urls, 'four unique players in page order, the sidebar teaser excluded');
        foreach ($media->candidates as $candidate) {
            self::assertNotNull($candidate->precedingText, 'every player knows the section it follows');
        }
    }

    /** Al Jazeera 469835: the VideoObject offers nothing but a Brightcove player page. */
    public function testAlJazeeraYieldsItsBrightcovePlayerWithTheDeclaredPoster(): void
    {
        $media = $this->scanner()->scan(
            $this->fixture('aljazeera-brightcove.html'),
            'https://www.aljazeera.com/video/newsfeed/2026/8/20/harry-kane-scores-goal',
        );

        self::assertCount(1, $media->candidates);
        self::assertSame(MediaKind::Embed, $media->candidates[0]->kind);
        self::assertSame(
            'https://players.brightcove.net/665003303001/6tKQRAx7lu_default/index.html?videoId=6403736850112',
            $media->candidates[0]->url,
        );
        self::assertStringContainsString('image-1787184739.jpg', (string) $media->candidates[0]->posterUrl);
    }

    /** ZDF 491430: contentUrl is an HLS playlist, embedUrl a first-party miniplayer nobody frames. */
    public function testZdfYieldsItsStreamWithTheDeclaredPoster(): void
    {
        $media = $this->scanner()->scan(
            $this->fixture('zdf-hls-video.html'),
            'https://www.zdfheute.de/video/zdf-morgenmagazin/istaf-berlin-em-stars-100.html',
        );

        self::assertCount(1, $media->candidates);
        self::assertSame(MediaKind::Stream, $media->candidates[0]->kind);
        self::assertSame(
            'https://www.zdfheute.de/api/video/istaf-berlin-em-stars-100.m3u8',
            $media->candidates[0]->url,
        );
        self::assertStringContainsString('1920x1080', (string) $media->candidates[0]->posterUrl);
    }

    public function testAnUnseenPublisherYieldsItsStreamAndItsBrightcovePlayerWithNoNewCode(): void
    {
        $media = $this->scanner()->scan(
            $this->fixture('unseen-hls-and-brightcove.html'),
            'https://unseen.test/two-ways',
        );

        $byKind = [];
        foreach ($media->candidates as $candidate) {
            $byKind[$candidate->kind->value] = $candidate->url;
        }
        self::assertSame('https://cdn.unseen.test/v/clip-one/master.m3u8', $byKind['stream'] ?? null);
        self::assertSame(
            'https://players.brightcove.net/123456789/AbCdEf_default/index.html?videoId=987654321',
            $byKind['embed'] ?? null,
        );
    }

    /** ardmediathek: the HLS master sits beside progressive mp4s; the file is the one player. */
    public function testAFileBesideAStreamYieldsTheFileOnly(): void
    {
        $media = $this->scanner()->scan(
            $this->fixture('file-beside-stream.html'),
            'https://www.mediathek.test/video/tv-2031',
        );

        $kinds = array_map(static fn ($c): MediaKind => $c->kind, $media->candidates);
        self::assertSame([MediaKind::Video], $kinds);
    }
}
