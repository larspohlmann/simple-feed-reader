<?php

declare(strict_types=1);

namespace App\Tests\Service\Content\Reader\Media\Support;

use App\Service\Content\Reader\Media\Support\LiveAudioStream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LiveAudioStreamTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function liveStreams(): iterable
    {
        yield 'icecast mount with mp3' => ['https://st01.sslstream.dlf.de/dlf/01/128/mp3/stream.mp3?aggregator=web'];
        yield 'bare stream mount' => ['https://radio.test/stream'];
        yield 'live aac' => ['https://radio.test/live.aac'];
        yield 'livestream' => ['https://radio.test/LiveStream.MP3'];
        yield 'sslstream host' => ['https://st01.sslstream.test/dlf/01/128/mp3/radio.mp3'];
        yield 'sslstream host in capitals' => ['https://ST01.SSLSTREAM.TEST/dlf/01/128/mp3/radio.mp3'];
        yield 'live path segment' => ['https://radio.test/live/episode.mp3'];
        yield 'pls playlist' => ['https://radio.test/listen.pls'];
        yield 'm3u playlist' => ['https://radio.test/listen.m3u'];
    }

    #[DataProvider('liveStreams')]
    public function testRecognisesALiveStream(string $url): void
    {
        self::assertTrue(LiveAudioStream::is($url));
    }

    /** @return iterable<string, array{string}> */
    public static function episodes(): iterable
    {
        yield 'episode mp3' => ['https://ondemand-mp3.dradio.de/file/dradio/2026/11/07/ohne_echte_lobby_dlf.mp3'];
        yield 'hls playlist' => ['https://radio.test/live.m3u8'];
        yield 'stream as a directory' => ['https://radio.test/stream/episode-12.mp3'];
        yield 'extensionless upload' => ['https://pub.test/api/v1/audio/upload/7adcfe96/src'];
        yield 'stream mp4 video' => ['https://radio.test/stream.mp4'];
        yield 'not a url' => ['stream.mp3'];
    }

    #[DataProvider('episodes')]
    public function testLeavesAnythingElse(string $url): void
    {
        self::assertFalse(LiveAudioStream::is($url));
    }
}
