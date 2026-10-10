<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\EmbedProvider;

use App\Service\Reader\Media\EmbedProvider\SpotifyEmbedProvider;
use App\Service\Reader\Media\Model\EmbedFrameModel;
use App\Service\Reader\Media\Model\EmbedKind;
use App\Service\Reader\Media\Model\EmbedShape;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SpotifyEmbedProviderTest extends TestCase
{
    private SpotifyEmbedProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new SpotifyEmbedProvider();
    }

    /** The Trancentral post's own markup: an embed iframe with a generator token. */
    public function testNormalizesAnEmbedUrlAndDropsTheToken(): void
    {
        self::assertSame(
            'https://open.spotify.com/embed/playlist/27uRYdAHvcKADidfnR8BN4',
            $this->provider->normalize(
                'https://open.spotify.com/embed/playlist/27uRYdAHvcKADidfnR8BN4?utm_source=generator',
            ),
        );
    }

    public function testNormalizesAShareUrlWithoutTheEmbedSegment(): void
    {
        self::assertSame(
            'https://open.spotify.com/embed/playlist/27uRYdAHvcKADidfnR8BN4',
            $this->provider->normalize('https://open.spotify.com/playlist/27uRYdAHvcKADidfnR8BN4?si=abcdef'),
        );
    }

    public function testNormalizesATrackUrl(): void
    {
        self::assertSame(
            'https://open.spotify.com/embed/track/4cOdK2wGLETKBW3PvgPWqT',
            $this->provider->normalize('https://open.spotify.com/track/4cOdK2wGLETKBW3PvgPWqT'),
        );
    }

    /** Older podcast embeds carry the `embed-podcast` path segment. */
    public function testNormalizesAnEmbedPodcastEpisodeUrl(): void
    {
        self::assertSame(
            'https://open.spotify.com/embed/episode/512ojhOuo1ktJprKbVcKyQ',
            $this->provider->normalize('https://open.spotify.com/embed-podcast/episode/512ojhOuo1ktJprKbVcKyQ'),
        );
    }

    public function testAlreadyEmbedIsIdempotent(): void
    {
        $url = 'https://open.spotify.com/embed/album/1DFixLWuPkv3KT3TnV35m3';

        self::assertSame($url, $this->provider->normalize($url));
    }

    public function testHasNoPoster(): void
    {
        self::assertNull($this->provider->poster('https://open.spotify.com/embed/playlist/27uRYdAHvcKADidfnR8BN4'));
    }

    public function testRejectsAnUnknownContentType(): void
    {
        self::assertFalse($this->provider->matches('https://open.spotify.com/embed/user/spotify'));
        self::assertNull($this->provider->normalize('https://open.spotify.com/user/spotify'));
    }

    public function testRejectsAnotherHost(): void
    {
        self::assertFalse($this->provider->matches('https://www.googletagmanager.com/ns.html?id=GTM-1'));
    }

    /** A look-alike host must not pass: the check is the host, not a substring. */
    public function testRejectsALookalikeHost(): void
    {
        self::assertFalse(
            $this->provider->matches('https://open.spotify.com.evil.test/embed/playlist/27uRYdAHvcKADidfnR8BN4'),
        );
    }

    public function testEveryFrameIsAnAudioPlayer(): void
    {
        foreach ($this->provider->frames() as $frame) {
            self::assertSame(EmbedKind::Audio, $frame->kind);
        }
    }

    /** @return iterable<string, array{0: string, 1: EmbedShape}> */
    public static function framesByShape(): iterable
    {
        yield 'track' => ['https://open.spotify.com/embed/track/4cOdK2wGLETKBW3PvgPWqT', EmbedShape::Landscape];
        yield 'episode' => ['https://open.spotify.com/embed/episode/4cOdK2wGLETKBW3PvgPWqT', EmbedShape::Landscape];
        yield 'playlist' => ['https://open.spotify.com/embed/playlist/27uRYdAHvcKADidfnR8BN4', EmbedShape::Tall];
        yield 'album' => ['https://open.spotify.com/embed/album/27uRYdAHvcKADidfnR8BN4', EmbedShape::Tall];
        yield 'artist' => ['https://open.spotify.com/embed/artist/27uRYdAHvcKADidfnR8BN4', EmbedShape::Tall];
        yield 'show' => ['https://open.spotify.com/embed/show/27uRYdAHvcKADidfnR8BN4', EmbedShape::Tall];
    }

    #[DataProvider('framesByShape')]
    public function testACollectionGetsATallBoxAndASingleItemALandscapeOne(string $url, EmbedShape $shape): void
    {
        $matching = array_values(array_filter(
            $this->provider->frames(),
            static fn (EmbedFrameModel $frame): bool => $frame->matches($url),
        ));

        self::assertCount(1, $matching);
        self::assertSame($shape, $matching[0]->shape);
    }
}
