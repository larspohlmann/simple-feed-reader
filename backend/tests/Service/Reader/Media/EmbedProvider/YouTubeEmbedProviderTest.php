<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\EmbedProvider;

use App\Service\Reader\Media\EmbedProvider\YouTubeEmbedProvider;
use App\Service\Reader\Media\Model\EmbedKind;
use App\Service\Reader\Media\Model\EmbedShape;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class YouTubeEmbedProviderTest extends TestCase
{
    use MatchesEmbedFrames;

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
            $this->provider->target('https://www.youtube.com/embed/M1j_uRqKMKI?si=abcdefgh')?->url
        );
    }

    public function testNormalizesAWatchUrl(): void
    {
        self::assertSame(
            'https://www.youtube-nocookie.com/embed/M1j_uRqKMKI',
            $this->provider->target('https://www.youtube.com/watch?v=M1j_uRqKMKI&t=30')?->url
        );
    }

    public function testNormalizesAShortUrl(): void
    {
        self::assertSame(
            'https://www.youtube-nocookie.com/embed/M1j_uRqKMKI',
            $this->provider->target('https://youtu.be/M1j_uRqKMKI')?->url
        );
    }

    public function testAlreadyNocookieIsIdempotent(): void
    {
        $url = 'https://www.youtube-nocookie.com/embed/M1j_uRqKMKI';

        self::assertSame($url, $this->provider->target($url)?->url);
    }

    public function testPosterIsTheThumbnailAndTheLabelNamesTheHost(): void
    {
        $target = $this->provider->target('https://www.youtube.com/embed/M1j_uRqKMKI');

        self::assertSame('https://i.ytimg.com/vi/M1j_uRqKMKI/hqdefault.jpg', $target?->posterUrl);
        self::assertSame('Watch on YouTube', $target?->label);
    }

    public function testRejectsAnIdOfTheWrongLength(): void
    {
        self::assertNull($this->provider->target('https://www.youtube.com/embed/tooshort'));
    }

    public function testRejectsAnotherHost(): void
    {
        self::assertNull($this->provider->target('https://www.googletagmanager.com/ns.html?id=GTM-1'));
    }

    /** A look-alike host must not pass: the check is the host, not a substring. */
    public function testRejectsALookalikeHost(): void
    {
        self::assertNull($this->provider->target('https://youtube.com.evil.test/embed/M1j_uRqKMKI'));
    }

    #[DataProvider('shortsUrls')]
    public function testReadsAShortAsAPortraitEmbed(string $url): void
    {
        $target = $this->provider->target($url);

        self::assertSame('https://www.youtube-nocookie.com/embed/GhUuOxrCato#shorts', $target?->url);
        self::assertSame('https://i.ytimg.com/vi/GhUuOxrCato/hqdefault.jpg', $target?->posterUrl);
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
        self::assertNull($this->provider->target('https://www.youtube.com/shorts/'));
        self::assertNull($this->provider->target('https://www.youtube.com/shorts/tooShort'));
    }

    /** @return iterable<string, array{0: string, 1: EmbedShape}> */
    public static function framesByShape(): iterable
    {
        yield 'video' => ['https://www.youtube-nocookie.com/embed/GhUuOxrCato', EmbedShape::Landscape];
        yield 'short' => ['https://www.youtube-nocookie.com/embed/GhUuOxrCato#shorts', EmbedShape::Portrait];
        yield 'short starting on load' => [
            'https://www.youtube-nocookie.com/embed/GhUuOxrCato?autoplay=1#shorts',
            EmbedShape::Portrait,
        ];
    }

    #[DataProvider('framesByShape')]
    public function testAShortGetsAPortraitVideoBoxAndAVideoALandscapeOne(string $url, EmbedShape $shape): void
    {
        $frame = self::frameMatching($this->provider->frames(), $url);

        self::assertSame(EmbedKind::Video, $frame->kind);
        self::assertSame($shape, $frame->shape);
    }

    public function testNoFrameAcceptsAnotherFragment(): void
    {
        self::assertSame(
            [],
            self::framesMatching($this->provider->frames(), 'https://www.youtube-nocookie.com/embed/GhUuOxrCato#other'),
        );
    }

    public function testNoFrameAcceptsAnotherQuery(): void
    {
        self::assertSame(
            [],
            self::framesMatching(
                $this->provider->frames(),
                'https://www.youtube-nocookie.com/embed/GhUuOxrCato?mute=1#shorts',
            ),
        );
    }
}
