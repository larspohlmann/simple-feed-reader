<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\EmbedProvider;

use App\Service\Reader\Media\EmbedProvider\YouTubeEmbedProvider;
use App\Service\Reader\Media\Model\EmbedFrameModel;
use App\Service\Reader\Media\Model\EmbedKind;
use App\Service\Reader\Media\Model\EmbedShape;
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

    /** @return iterable<string, array{0: string, 1: EmbedShape}> */
    public static function framesByShape(): iterable
    {
        yield 'video' => ['https://www.youtube-nocookie.com/embed/GhUuOxrCato', EmbedShape::Landscape];
        yield 'short' => ['https://www.youtube-nocookie.com/embed/GhUuOxrCato#shorts', EmbedShape::Portrait];
    }

    #[DataProvider('framesByShape')]
    public function testAShortGetsAPortraitVideoBoxAndAVideoALandscapeOne(string $url, EmbedShape $shape): void
    {
        $matching = $this->framesMatching($url);

        self::assertCount(1, $matching);
        self::assertSame(EmbedKind::Video, $matching[0]->kind);
        self::assertSame($shape, $matching[0]->shape);
    }

    public function testNoFrameAcceptsAnotherFragment(): void
    {
        self::assertSame([], $this->framesMatching('https://www.youtube-nocookie.com/embed/GhUuOxrCato#other'));
    }

    /** @return list<EmbedFrameModel> */
    private function framesMatching(string $url): array
    {
        return array_values(array_filter(
            $this->provider->frames(),
            static fn (EmbedFrameModel $frame): bool => $frame->matches($url),
        ));
    }
}
