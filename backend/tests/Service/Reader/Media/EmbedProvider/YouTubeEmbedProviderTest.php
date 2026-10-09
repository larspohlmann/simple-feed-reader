<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\EmbedProvider;

use App\Service\Reader\Media\EmbedProvider\YouTubeEmbedProvider;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('shortsUrls')]
    public function testReadsAShortAsAPortraitEmbed(string $url): void
    {
        self::assertTrue($this->provider->matches($url));
        self::assertSame(
            'https://www.youtube-nocookie.com/embed/GhUuOxrCato#shorts',
            $this->provider->normalize($url)
        );
        self::assertSame(
            'https://i.ytimg.com/vi/GhUuOxrCato/hqdefault.jpg',
            $this->provider->poster($url)
        );
    }

    /** @return iterable<string, array{string}> */
    public static function shortsUrls(): iterable
    {
        yield 'www' => ['https://www.youtube.com/shorts/GhUuOxrCato'];
        yield 'mobile host' => ['https://m.youtube.com/shorts/GhUuOxrCato'];
        yield 'bare host with a share query' => ['https://youtube.com/shorts/GhUuOxrCato?feature=share'];
    }

    public function testRejectsAShortsPathWithoutAnId(): void
    {
        self::assertFalse($this->provider->matches('https://www.youtube.com/shorts/'));
        self::assertFalse($this->provider->matches('https://www.youtube.com/shorts/tooShort'));
    }

    public function testFramePatternAcceptsTheShortMarkerAndNothingElse(): void
    {
        $pattern = '~' . $this->provider->framePattern() . '~';

        self::assertSame(1, preg_match($pattern, 'https://www.youtube-nocookie.com/embed/GhUuOxrCato#shorts'));
        self::assertSame(1, preg_match($pattern, 'https://www.youtube-nocookie.com/embed/GhUuOxrCato'));
        self::assertSame(0, preg_match($pattern, 'https://www.youtube-nocookie.com/embed/GhUuOxrCato#other'));
    }
}
