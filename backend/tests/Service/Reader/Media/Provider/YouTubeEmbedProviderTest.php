<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Provider;

use App\Service\Reader\Media\Provider\YouTubeEmbedProvider;
use PHPUnit\Framework\TestCase;

final class YouTubeEmbedProviderTest extends TestCase
{
    private YouTubeEmbedProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new YouTubeEmbedProvider();
    }

    /** The OZORA listicle's own markup: a share token in the query. */
    public function testNormalizesAnEmbedUrlAndDropsTheShareToken(): void
    {
        self::assertSame(
            'https://www.youtube-nocookie.com/embed/M1j_uRqKMKI',
            $this->provider->normalize('https://www.youtube.com/embed/M1j_uRqKMKI?si=abcdefgh')
        );
    }

    public function testNormalizesAWatchUrl(): void
    {
        self::assertSame(
            'https://www.youtube-nocookie.com/embed/M1j_uRqKMKI',
            $this->provider->normalize('https://www.youtube.com/watch?v=M1j_uRqKMKI&t=30')
        );
    }

    public function testNormalizesAShortUrl(): void
    {
        self::assertSame(
            'https://www.youtube-nocookie.com/embed/M1j_uRqKMKI',
            $this->provider->normalize('https://youtu.be/M1j_uRqKMKI')
        );
    }

    public function testAlreadyNocookieIsIdempotent(): void
    {
        $url = 'https://www.youtube-nocookie.com/embed/M1j_uRqKMKI';

        self::assertSame($url, $this->provider->normalize($url));
    }

    public function testPosterIsTheThumbnail(): void
    {
        self::assertSame(
            'https://i.ytimg.com/vi/M1j_uRqKMKI/hqdefault.jpg',
            $this->provider->poster('https://www.youtube.com/embed/M1j_uRqKMKI')
        );
    }

    public function testRejectsAnIdOfTheWrongLength(): void
    {
        self::assertFalse($this->provider->matches('https://www.youtube.com/embed/tooshort'));
        self::assertNull($this->provider->normalize('https://www.youtube.com/embed/tooshort'));
    }

    public function testRejectsAnotherHost(): void
    {
        self::assertFalse($this->provider->matches('https://www.googletagmanager.com/ns.html?id=GTM-1'));
    }

    /** A look-alike host must not pass: the check is the host, not a substring. */
    public function testRejectsALookalikeHost(): void
    {
        self::assertFalse($this->provider->matches('https://youtube.com.evil.test/embed/M1j_uRqKMKI'));
    }
}
