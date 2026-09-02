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
}
