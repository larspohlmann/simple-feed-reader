<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Provider;

use App\Service\Reader\Media\Provider\DailymotionEmbedProvider;
use PHPUnit\Framework\TestCase;

final class DailymotionEmbedProviderTest extends TestCase
{
    private DailymotionEmbedProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new DailymotionEmbedProvider();
    }

    public function testNormalizesAnEmbedUrl(): void
    {
        self::assertSame(
            'https://www.dailymotion.com/embed/video/x7tgad0',
            $this->provider->normalize('https://www.dailymotion.com/embed/video/x7tgad0'),
        );
    }

    public function testNormalizesAWatchUrlAndDropsTheSlug(): void
    {
        self::assertSame(
            'https://www.dailymotion.com/embed/video/x7tgad0',
            $this->provider->normalize('https://www.dailymotion.com/video/x7tgad0_some-title-slug'),
        );
    }

    public function testNormalizesAShortUrl(): void
    {
        self::assertSame(
            'https://www.dailymotion.com/embed/video/x7tgad0',
            $this->provider->normalize('https://dai.ly/x7tgad0'),
        );
    }

    public function testAlreadyEmbedIsIdempotent(): void
    {
        $url = 'https://www.dailymotion.com/embed/video/x7tgad0';

        self::assertSame($url, $this->provider->normalize($url));
    }

    public function testHasNoPoster(): void
    {
        self::assertNull($this->provider->poster('https://www.dailymotion.com/embed/video/x7tgad0'));
    }

    public function testRejectsAPathWithoutAVideoId(): void
    {
        self::assertFalse($this->provider->matches('https://www.dailymotion.com/embed/video/'));
        self::assertNull($this->provider->normalize('https://www.dailymotion.com/playlist/x7tgad0'));
    }

    public function testRejectsAnotherHost(): void
    {
        self::assertFalse($this->provider->matches('https://www.googletagmanager.com/ns.html?id=GTM-1'));
    }

    /** A look-alike host must not pass: the check is the host, not a substring. */
    public function testRejectsALookalikeHost(): void
    {
        self::assertFalse($this->provider->matches('https://www.dailymotion.com.evil.test/embed/video/x7tgad0'));
    }
}
