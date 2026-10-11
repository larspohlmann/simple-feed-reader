<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\EmbedProvider;

use App\Service\Reader\Media\EmbedProvider\DailymotionEmbedProvider;
use App\Service\Reader\Media\Model\EmbedKind;
use App\Service\Reader\Media\Model\EmbedShape;
use PHPUnit\Framework\TestCase;

final class DailymotionEmbedProviderTest extends TestCase
{
    use MatchesEmbedFrames;

    private DailymotionEmbedProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new DailymotionEmbedProvider();
    }

    public function testNormalizesAnEmbedUrl(): void
    {
        self::assertSame(
            'https://www.dailymotion.com/embed/video/x7tgad0',
            $this->provider->target('https://www.dailymotion.com/embed/video/x7tgad0')?->url,
        );
    }

    public function testNormalizesAWatchUrlAndDropsTheSlug(): void
    {
        self::assertSame(
            'https://www.dailymotion.com/embed/video/x7tgad0',
            $this->provider->target('https://www.dailymotion.com/video/x7tgad0_some-title-slug')?->url,
        );
    }

    public function testNormalizesAShortUrl(): void
    {
        self::assertSame(
            'https://www.dailymotion.com/embed/video/x7tgad0',
            $this->provider->target('https://dai.ly/x7tgad0')?->url,
        );
    }

    public function testAlreadyEmbedIsIdempotent(): void
    {
        $url = 'https://www.dailymotion.com/embed/video/x7tgad0';

        self::assertSame($url, $this->provider->target($url)?->url);
    }

    public function testOffersNoPosterAndNamesTheHost(): void
    {
        $target = $this->provider->target('https://www.dailymotion.com/embed/video/x7tgad0');

        self::assertNotNull($target);
        self::assertNull($target->posterUrl);
        self::assertSame('Watch on Dailymotion', $target->label);
    }

    public function testRejectsAPathWithoutAVideoId(): void
    {
        self::assertNull($this->provider->target('https://www.dailymotion.com/embed/video/'));
        self::assertNull($this->provider->target('https://www.dailymotion.com/playlist/x7tgad0'));
    }

    public function testRejectsAnotherHost(): void
    {
        self::assertNull($this->provider->target('https://www.googletagmanager.com/ns.html?id=GTM-1'));
    }

    /** A look-alike host must not pass: the check is the host, not a substring. */
    public function testRejectsALookalikeHost(): void
    {
        self::assertNull($this->provider->target('https://www.dailymotion.com.evil.test/embed/video/x7tgad0'));
    }

    public function testIsAVideoPlayerInALandscapeBox(): void
    {
        $frame = self::onlyFrame($this->provider);

        self::assertSame(EmbedKind::Video, $frame->kind);
        self::assertSame(EmbedShape::Landscape, $frame->shape);
    }
}
