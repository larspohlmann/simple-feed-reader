<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Source;

use App\Service\Reader\Media\DurableMediaUrl;
use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\MediaUrlKind;
use App\Service\Reader\Media\Provider\SoundCloudEmbedProvider;
use App\Service\Reader\Media\Provider\YouTubeEmbedProvider;
use App\Service\Reader\Media\Source\MetaMediaSource;
use PHPUnit\Framework\TestCase;

final class MetaMediaSourceTest extends TestCase
{
    private MetaMediaSource $source;

    protected function setUp(): void
    {
        $this->source = new MetaMediaSource(
            new MediaUrlKind(
                new DurableMediaUrl(),
                new EmbedProviders([new YouTubeEmbedProvider(), new SoundCloudEmbedProvider()]),
            ),
        );
    }

    public function testTakesOgAudioWhenItIsAFile(): void
    {
        $html = '<html><head><meta property="og:audio" content="https://x.test/a.mp3"></head></html>';

        $found = $this->source->find($html, 'https://x.test/a.html');

        self::assertCount(1, $found);
        self::assertSame(MediaKind::Audio, $found[0]->kind);
    }

    /** ARD's og:video is a player PAGE, not a file. It must be refused. */
    public function testRefusesAnOgVideoThatPointsAtAPlayerPage(): void
    {
        $html = '<html><head><meta property="og:video" '
            . 'content="https://www.tagesschau.de/video/video-1640158~player.html"></head></html>';

        self::assertSame([], $this->source->find($html, 'https://www.tagesschau.de/x.html'));
    }

    public function testRefusesTheCapturedArdPageThroughThisLayer(): void
    {
        $html = file_get_contents(__DIR__ . '/../../../../Fixtures/reader/media/ard-video.html');
        self::assertIsString($html);

        self::assertSame([], $this->source->find($html, 'https://www.tagesschau.de/x.html'));
    }
}
