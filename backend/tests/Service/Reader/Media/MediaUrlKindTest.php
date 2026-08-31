<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media;

use App\Service\Reader\Media\DurableMediaUrl;
use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\MediaUrlKind;
use App\Service\Reader\Media\Provider\SoundCloudEmbedProvider;
use App\Service\Reader\Media\Provider\YouTubeEmbedProvider;
use PHPUnit\Framework\TestCase;

final class MediaUrlKindTest extends TestCase
{
    private MediaUrlKind $kind;

    protected function setUp(): void
    {
        $this->kind = new MediaUrlKind(
            new DurableMediaUrl(),
            new EmbedProviders([new YouTubeEmbedProvider(), new SoundCloudEmbedProvider()]),
        );
    }

    public function testRecognisesAudioByExtension(): void
    {
        $a = $this->kind->resolve('https://x.test/a.mp3');
        self::assertNotNull($a);
        self::assertSame(MediaKind::Audio, $a->kind);
        self::assertSame('https://x.test/a.mp3', $a->url);

        $m4a = $this->kind->resolve('https://x.test/a.m4a');
        self::assertNotNull($m4a);
        self::assertSame(MediaKind::Audio, $m4a->kind);
    }

    public function testRecognisesVideoByExtension(): void
    {
        $video = $this->kind->resolve('https://x.test/v.mp4');

        self::assertNotNull($video);
        self::assertSame(MediaKind::Video, $video->kind);
        self::assertSame('https://x.test/v.mp4', $video->url);
    }

    public function testRecognisesAnEmbedByProviderAndReturnsItsCanonicalUrl(): void
    {
        $embed = $this->kind->resolve('https://www.youtube.com/watch?v=M1j_uRqKMKI');

        self::assertNotNull($embed);
        self::assertSame(MediaKind::Embed, $embed->kind);
        self::assertStringContainsString('M1j_uRqKMKI', $embed->url);
    }

    /**
     * The trap ARD sets: og:video points at a player PAGE. Treating that as
     * video would put an HTML document in a <video src>.
     */
    public function testRejectsAPlayerPage(): void
    {
        self::assertNull($this->kind->resolve('https://www.tagesschau.de/video/video-1640158~player.html'));
    }

    public function testRejectsAnImage(): void
    {
        self::assertNull($this->kind->resolve('https://x.test/photo.jpg'));
    }

    public function testRejectsAnHlsPlaylist(): void
    {
        self::assertNull($this->kind->resolve('https://x.test/master.m3u8'));
    }

    /** A query is stripped before the extension is read, then re-checked. */
    public function testStripsAQueryBeforeJudging(): void
    {
        $resolved = $this->kind->resolve('https://x.test/a.mp3?t=progseg&sc=siteplayer');

        self::assertNotNull($resolved);
        self::assertSame(MediaKind::Audio, $resolved->kind);
        self::assertSame('https://x.test/a.mp3', $resolved->url);
    }

    /** The exact failure this refactor closes: a signed url must never survive into the resolved form. */
    public function testStripsASignedUrlsQueryBeforeEmitting(): void
    {
        $resolved = $this->kind->resolve('https://x.test/v.mp4?Expires=1&Signature=abc');

        self::assertNotNull($resolved);
        self::assertSame(MediaKind::Video, $resolved->kind);
        self::assertSame('https://x.test/v.mp4', $resolved->url);
    }

    /** DurableMediaUrl's exclusions still bind: narration is not this article. */
    public function testRejectsMachineNarration(): void
    {
        self::assertNull($this->kind->resolve('https://x.test/tts/a-OnyxTurboMultilingualNeural.mp3'));
    }

    public function testRejectsALiveStream(): void
    {
        self::assertNull($this->kind->resolve('https://st01.sslstream.dlf.de/dlf/01/128/mp3/stream.mp3'));
    }
}
