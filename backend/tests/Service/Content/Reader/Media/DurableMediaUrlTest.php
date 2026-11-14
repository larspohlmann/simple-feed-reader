<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media;

use App\Service\Reader\Media\DurableMediaUrl;
use PHPUnit\Framework\TestCase;

final class DurableMediaUrlTest extends TestCase
{
    private DurableMediaUrl $guard;

    protected function setUp(): void
    {
        $this->guard = new DurableMediaUrl();
    }

    public function testAcceptsAPlainHttpsFile(): void
    {
        self::assertTrue($this->guard->accepts('https://ondemand-mp3.dradio.de/file/dradio/2026/08/a.mp3'));
    }

    public function testRejectsHttp(): void
    {
        self::assertFalse($this->guard->accepts('http://ondemand-mp3.dradio.de/a.mp3'));
    }

    /**
     * Adapters strip the query before the guard sees the URL, so anything left
     * is unexplained and may be a signature.
     */
    public function testRejectsAnyRemainingQueryString(): void
    {
        self::assertFalse($this->guard->accepts('https://cdn.example.com/a.mp3?Expires=1&Signature=x'));
        self::assertFalse($this->guard->accepts('https://cdn.example.com/a.mp3?utm_source=x'));
    }

    /** Substack's machine narration: public, unsigned, 200 audio/mpeg, and wrong. */
    public function testRejectsMachineNarration(): void
    {
        self::assertFalse($this->guard->accepts(
            'https://substack-video.s3.amazonaws.com/video_upload/post/1/tts/x-OnyxTurboMultilingualNeural.mp3'
        ));
        self::assertFalse($this->guard->accepts('https://cdn.example.com/audio/x-OnyxTurboMultilingualNeural.mp3'));
    }

    public function testRejectsALiveStream(): void
    {
        self::assertFalse($this->guard->accepts('https://st01.sslstream.dlf.de/dlf/01/128/mp3/stream.mp3'));
        self::assertFalse($this->guard->accepts('https://example.com/live/stream.mp3'));
    }

    public function testRejectsAMalformedUrl(): void
    {
        self::assertFalse($this->guard->accepts('not-a-url'));
        self::assertFalse($this->guard->accepts(''));
    }
}
