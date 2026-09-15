<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media;

use App\Service\Reader\Media\EmbedProviders;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EmbedProvidersWiringTest extends KernelTestCase
{
    private function providers(): EmbedProviders
    {
        self::bootKernel();
        $providers = self::getContainer()->get(EmbedProviders::class);
        self::assertInstanceOf(EmbedProviders::class, $providers);

        return $providers;
    }

    public function testYouTubeResolvesThroughTheTaggedIterator(): void
    {
        $target = $this->providers()->resolve('https://www.youtube.com/embed/M1j_uRqKMKI?si=x');

        self::assertNotNull($target);
        self::assertSame('https://www.youtube-nocookie.com/embed/M1j_uRqKMKI', $target->url);
    }

    public function testSoundCloudResolvesThroughTheTaggedIterator(): void
    {
        $target = $this->providers()->resolve(
            'https://w.soundcloud.com/player/?url=https%3A//api.soundcloud.com/tracks/2370150908&auto_play=true'
        );

        self::assertNotNull($target);
        self::assertStringNotContainsString('auto_play', $target->url);
    }

    public function testAnUnknownHostResolvesToNull(): void
    {
        self::assertNull($this->providers()->resolve('https://www.googletagmanager.com/ns.html?id=GTM-1'));
    }

    public function testBrightcoveResolvesThroughTheTaggedIterator(): void
    {
        $target = $this->providers()->resolve(
            'https://players.brightcove.net/665003303001/6tKQRAx7lu_default/index.html?videoId=6403736850112&autoplay=1'
        );

        self::assertNotNull($target);
        self::assertSame(
            'https://players.brightcove.net/665003303001/6tKQRAx7lu_default/index.html?videoId=6403736850112',
            $target->url,
        );
    }

    public function testVimeoResolvesThroughTheTaggedIterator(): void
    {
        $target = $this->providers()->resolve('https://vimeo.com/1226652197/');

        self::assertNotNull($target);
        self::assertSame('https://player.vimeo.com/video/1226652197', $target->url);
    }

    public function testSpotifyResolvesThroughTheTaggedIterator(): void
    {
        $target = $this->providers()->resolve(
            'https://open.spotify.com/embed/playlist/27uRYdAHvcKADidfnR8BN4?utm_source=generator'
        );

        self::assertNotNull($target);
        self::assertSame('https://open.spotify.com/embed/playlist/27uRYdAHvcKADidfnR8BN4', $target->url);
    }

    /** Readability keeps an in-body frame only when the generated regex claims its source host (#1053). */
    public function testTheVideoEmbedRegexKeepsEveryProviderSourceHost(): void
    {
        $regex = $this->providers()->videoEmbedRegex();
        $sources = [
            'https://www.youtube.com/embed/M1j_uRqKMKI',
            'https://player.vimeo.com/video/1226652197',
            'https://w.soundcloud.com/player/?url=x',
            'https://players.brightcove.net/1/x/index.html?videoId=2',
            'https://open.spotify.com/embed/playlist/27uRYdAHvcKADidfnR8BN4',
            'https://www.dailymotion.com/embed/video/x7tgad0',
        ];

        foreach ($sources as $url) {
            self::assertSame(1, preg_match($regex, $url), $url . ' is not kept by the embed regex.');
        }

        self::assertSame(0, preg_match($regex, 'https://www.googletagmanager.com/ns.html?id=GTM-1'));
    }
}
