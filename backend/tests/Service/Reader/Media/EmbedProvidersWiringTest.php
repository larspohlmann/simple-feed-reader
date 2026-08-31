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
}
