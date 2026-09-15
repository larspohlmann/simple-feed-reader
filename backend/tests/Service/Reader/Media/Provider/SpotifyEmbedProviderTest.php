<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Provider;

use App\Service\Reader\Media\Provider\SpotifyEmbedProvider;
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
}
