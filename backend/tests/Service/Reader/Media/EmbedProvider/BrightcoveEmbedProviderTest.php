<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\EmbedProvider;

use App\Service\Reader\Media\EmbedProvider\BrightcoveEmbedProvider;
use App\Service\Reader\Media\Model\EmbedKind;
use App\Service\Reader\Media\Model\EmbedShape;
use PHPUnit\Framework\TestCase;

final class BrightcoveEmbedProviderTest extends TestCase
{
    use MatchesEmbedFrames;

    private const string AL_JAZEERA =
        'https://players.brightcove.net/665003303001/6tKQRAx7lu_default/index.html?videoId=6403736850112';

    private BrightcoveEmbedProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new BrightcoveEmbedProvider();
    }

    /** Al Jazeera 469835: the VideoObject's embedUrl, exactly as declared. */
    public function testNormalizesADeclaredPlayerUrlToItself(): void
    {
        self::assertSame(self::AL_JAZEERA, $this->provider->target(self::AL_JAZEERA)?->url);
    }

    public function testKeepsOnlyTheVideoIdOfTheQuery(): void
    {
        self::assertSame(
            self::AL_JAZEERA,
            $this->provider->target(self::AL_JAZEERA . '&autoplay=1&muted=true')?->url,
        );
    }

    public function testKeepsThePlayerIdVerbatim(): void
    {
        $url = 'https://players.brightcove.net/123/AbC_x-1/index.html?videoId=456';

        self::assertSame($url, $this->provider->target($url)?->url);
    }

    public function testRejectsAPlayerUrlWithoutAVideoId(): void
    {
        self::assertNull($this->provider->target('https://players.brightcove.net/123/abc_default/index.html'));
    }

    public function testRejectsANonNumericVideoId(): void
    {
        $url = 'https://players.brightcove.net/123/abc_default/index.html?videoId=x';
        self::assertNull($this->provider->target($url));
    }

    public function testRejectsALookAlikeHost(): void
    {
        $lookAlikeHost = 'https://players.brightcove.net.evil.test/123/abc_default/index.html?videoId=1';
        $httpScheme = 'http://players.brightcove.net/123/abc_default/index.html?videoId=1';

        self::assertNull($this->provider->target($lookAlikeHost));
        self::assertNull($this->provider->target($httpScheme));
    }

    public function testOffersNoPosterAndAGenericLabel(): void
    {
        $target = $this->provider->target(self::AL_JAZEERA);

        self::assertNotNull($target);
        self::assertNull($target->posterUrl);
        self::assertSame('Watch the video', $target->label);
    }

    public function testIsAVideoPlayerInALandscapeBox(): void
    {
        $frame = self::onlyFrame($this->provider);

        self::assertSame(EmbedKind::Video, $frame->kind);
        self::assertSame(EmbedShape::Landscape, $frame->shape);
    }
}
