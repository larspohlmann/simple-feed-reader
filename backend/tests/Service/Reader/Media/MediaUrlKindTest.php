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
        self::assertSame(MediaKind::Audio, $this->kind->of('https://x.test/a.mp3'));
        self::assertSame(MediaKind::Audio, $this->kind->of('https://x.test/a.m4a'));
    }

    public function testRecognisesVideoByExtension(): void
    {
        self::assertSame(MediaKind::Video, $this->kind->of('https://x.test/v.mp4'));
    }

    public function testRecognisesAnEmbedByProvider(): void
    {
        self::assertSame(MediaKind::Embed, $this->kind->of('https://www.youtube.com/watch?v=M1j_uRqKMKI'));
    }

    /**
     * The trap ARD sets: og:video points at a player PAGE. Treating that as
     * video would put an HTML document in a <video src>.
     */
    public function testRejectsAPlayerPage(): void
    {
        self::assertNull($this->kind->of('https://www.tagesschau.de/video/video-1640158~player.html'));
    }

    public function testRejectsAnImage(): void
    {
        self::assertNull($this->kind->of('https://x.test/photo.jpg'));
    }

    public function testRejectsAnHlsPlaylist(): void
    {
        self::assertNull($this->kind->of('https://x.test/master.m3u8'));
    }

    /** A query is stripped before the extension is read, then re-checked. */
    public function testStripsAQueryBeforeJudging(): void
    {
        self::assertSame(MediaKind::Audio, $this->kind->of('https://x.test/a.mp3?t=progseg&sc=siteplayer'));
    }

    /** DurableMediaUrl's exclusions still bind: narration is not this article. */
    public function testRejectsMachineNarration(): void
    {
        self::assertNull($this->kind->of('https://x.test/tts/a-OnyxTurboMultilingualNeural.mp3'));
    }

    public function testRejectsALiveStream(): void
    {
        self::assertNull($this->kind->of('https://st01.sslstream.dlf.de/dlf/01/128/mp3/stream.mp3'));
    }
}
