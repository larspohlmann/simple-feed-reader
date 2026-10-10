<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media;

use App\Service\Reader\Media\Model\MediaKind;
use App\Tests\Support\ReadsFixtures;
use App\Tests\Support\ReadsCandidateUrls;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Every measured page, through the real container. The generic sources carry the whole set host-agnostically;
 * ZdfPlayerConfigSource is the one deliberate host-keyed exception.
 */
final class HostAgnosticDiscoveryTest extends KernelTestCase
{
    use ReadsFixtures;
    use ReadsCandidateUrls;
    use ScansWithTheWiredSources;


    public function testDeutschlandradioYieldsItsEpisode(): void
    {
        $media = $this->scan(
            $this->fixture('reader/media/deutschlandradio-audio.html'),
            'https://www.deutschlandfunkkultur.de/bildung-100.html',
        );

        self::assertSame(MediaKind::Audio, $media->candidates[0]->kind);
        self::assertStringNotContainsString('sslstream', $media->candidates[0]->url);
        self::assertStringContainsString('bildung', $media->candidates[0]->url);
    }

    public function testNprYieldsItsSegment(): void
    {
        $media = $this->scan(
            $this->fixture('reader/media/npr-audio.html'),
            'https://www.npr.org/2026/08/30/nx-s1-5948814/launch-nancy-grace-roman-space-telescope-nasa',
        );

        self::assertFalse($media->isEmpty());
        self::assertStringContainsString('telescope', $media->candidates[0]->url);
    }

    public function testArdYieldsAVideoWithAPoster(): void
    {
        $media = $this->scan(
            $this->fixture('reader/media/ard-video.html'),
            'https://www.tagesschau.de/ausland/beispiel-100.html',
        );

        $video = array_values(array_filter(
            $media->candidates,
            static fn ($candidate): bool => $candidate->kind === MediaKind::Video,
        ));

        self::assertNotSame([], $video);
        self::assertNotNull($video[0]->posterUrl);
    }

    public function testHeiseYieldsItsCompanionVideo(): void
    {
        $media = $this->scan($this->fixture('reader/media/heise-video.html'), 'https://www.heise.de/news/x.html');

        self::assertStringContainsString('M1j_uRqKMKI', $media->candidates[0]->url);
    }

    public function testFiveMagazineYieldsItsTrack(): void
    {
        $media = $this->scan($this->fixture('reader/media/soundcloud-page.html'), 'https://5mag.net/audio/dj-set/');

        self::assertStringContainsString('soundcloud', $media->candidates[0]->url);
    }

    public function testAnUnseenPublisherYieldsItsMediaWithNoNewCode(): void
    {
        $media = $this->scan(
            $this->fixture('reader/media/unseen-publisher.html'),
            'https://9to5mac.com/2026/08/27/happy-hour-605/',
        );

        self::assertFalse($media->isEmpty());
        self::assertSame(MediaKind::Audio, $media->candidates[0]->kind);
        self::assertStringEndsWith('.mp3', $media->candidates[0]->url);
    }

    /** The related-content sidebar's podcast is not this article's audio. */
    public function testASidebarTeaserDoesNotBecomeTheArticlesMedia(): void
    {
        $media = $this->scan(
            $this->fixture('reader/media/sidebar-teaser.html'),
            'https://www.tagesschau.de/inland/innenpolitik/merz-linke-sachsen-anhalt-100.html',
        );

        $kinds = array_map(static fn ($candidate): MediaKind => $candidate->kind, $media->candidates);
        self::assertSame([MediaKind::Video], $kinds);
    }

    /** JSON-LD declares one of four videos; the other three exist only as page embeds inside <noscript>. */
    public function testAPageThatDeclaresOneOfFourVideosYieldsAllFourInPageOrder(): void
    {
        $media = $this->scan(
            $this->fixture('reader/media/multi-embed-page.html'),
            'https://www.vice.com/en/article/4-remixes-from-the-2000s/',
        );

        self::assertSame([
            'https://www.youtube-nocookie.com/embed/aaaaaaaaaa1',
            'https://www.youtube-nocookie.com/embed/aaaaaaaaaa2',
            'https://www.youtube-nocookie.com/embed/aaaaaaaaaa3',
            'https://www.youtube-nocookie.com/embed/aaaaaaaaaa4',
        ], $this->urlsOf($media->candidates), 'four unique players in page order, the sidebar teaser excluded');
        foreach ($media->candidates as $candidate) {
            self::assertNotNull($candidate->precedingText, 'every player knows the section it follows');
        }
    }

    /** The VideoObject offers nothing but a Brightcove player page. */
    public function testAlJazeeraYieldsItsBrightcovePlayerWithTheDeclaredPoster(): void
    {
        $media = $this->scan(
            $this->fixture('reader/media/aljazeera-brightcove.html'),
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

    /** The contentUrl is an HLS playlist, the embedUrl a first-party miniplayer nobody frames. */
    public function testZdfYieldsItsStreamWithTheDeclaredPoster(): void
    {
        $media = $this->scan(
            $this->fixture('reader/media/zdf-hls-video.html'),
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
        $media = $this->scan(
            $this->fixture('reader/media/unseen-hls-and-brightcove.html'),
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

    /** The HLS master sits beside progressive mp4s; the file is the one player. */
    public function testAFileBesideAStreamYieldsTheFileOnly(): void
    {
        $media = $this->scan(
            $this->fixture('reader/media/file-beside-stream.html'),
            'https://www.mediathek.test/video/tv-2031',
        );

        $kinds = array_map(static fn ($candidate): MediaKind => $candidate->kind, $media->candidates);
        self::assertSame([MediaKind::Video], $kinds);
    }

    public function testTheGuardianYieldsItsYouTubeAtomAndNotTheSidebarOne(): void
    {
        $media = $this->scan(
            $this->fixture('reader/media/guardian-youtube-atom.html'),
            'https://www.theguardian.com/science/video/2026/sep/01/could-humans-ever-communicate-with-whales-video',
        );

        self::assertCount(1, $media->candidates);
        self::assertSame(MediaKind::Embed, $media->candidates[0]->kind);
        self::assertSame('https://www.youtube-nocookie.com/embed/pz8VRrI0p0U', $media->candidates[0]->url);
    }

    /** A broadcast page has no og:image; the still beside the player is the poster. */
    public function testABroadcastPageWithoutOgImageYieldsItsVideoWithThePlayersStillAndItsAudio(): void
    {
        $media = $this->scan(
            $this->fixture('reader/media/ard-broadcast-no-og-image.html'),
            'https://www.tagesschau.de/tagesschau_in_einfacher_sprache/tse-1410.html',
        );

        $kinds = array_map(static fn ($candidate) => $candidate->kind, $media->candidates);
        self::assertContains(MediaKind::Video, $kinds);
        self::assertContains(MediaKind::Audio, $kinds);
        self::assertNotContains(MediaKind::Stream, $kinds, 'the file beside the HLS master wins');
        $videos = array_values(array_filter(
            $media->candidates,
            static fn ($candidate): bool => $candidate->kind === MediaKind::Video,
        ));
        self::assertStringContainsString('sendungsbild-1789662', (string) $videos[0]->posterUrl);
    }

    /**
     * ZDF names each clip only in a player config, and a text-led page ships no VideoObject to seed the generic rule,
     * so ZdfPlayerConfigSource seeds a stream per declared player.
     */
    public function testTheZdfPlayerConfigsEachSeedAStream(): void
    {
        $media = $this->scan(
            $this->fixture('reader/media/zdf-sibling-video-configs.html'),
            'https://www.zdfheute.de/politik/deutschland/leipzig-drohne-sabotage-100.html',
        );

        self::assertCount(4, $media->candidates);
        foreach ($media->candidates as $candidate) {
            self::assertSame(MediaKind::Stream, $candidate->kind);
        }
    }
}
