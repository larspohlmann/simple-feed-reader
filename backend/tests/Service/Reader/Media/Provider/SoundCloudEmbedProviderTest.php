<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Provider;

use App\Service\Reader\Media\Provider\SoundCloudEmbedProvider;
use PHPUnit\Framework\TestCase;

final class SoundCloudEmbedProviderTest extends TestCase
{
    private SoundCloudEmbedProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new SoundCloudEmbedProvider();
    }

    /** Copied from 5 Magazine's page: autoplay and chrome flags must not survive. */
    public function testNormalizesTheTrackAndDropsAutoplay(): void
    {
        $source = 'https://w.soundcloud.com/player/?url=https%3A//api.soundcloud.com/tracks/2370150908'
            . '&color=%23ff5500&auto_play=true&hide_related=true&show_comments=true';

        self::assertSame(
            'https://w.soundcloud.com/player/?url=https%3A%2F%2Fapi.soundcloud.com%2Ftracks%2F2370150908',
            $this->provider->normalize($source)
        );
    }

    public function testHasNoPosterSoItFallsBackToALabel(): void
    {
        $url = 'https://w.soundcloud.com/player/?url=https%3A//api.soundcloud.com/tracks/2370150908';

        self::assertNull($this->provider->poster($url));
        self::assertSame('Listen on SoundCloud', $this->provider->label());
    }

    public function testRejectsAPlayerWithoutATrackId(): void
    {
        self::assertFalse($this->provider->matches('https://w.soundcloud.com/player/?url=https%3A//example.test/x'));
    }

    public function testRejectsANonNumericTrackId(): void
    {
        self::assertFalse($this->provider->matches(
            'https://w.soundcloud.com/player/?url=https%3A//api.soundcloud.com/tracks/abc'
        ));
    }

    public function testRejectsAnotherHost(): void
    {
        self::assertFalse($this->provider->matches('https://player.example.test/?url=x'));
    }
}
