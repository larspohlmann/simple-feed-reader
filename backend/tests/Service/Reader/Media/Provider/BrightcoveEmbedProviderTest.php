<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Provider;

use App\Service\Reader\Media\Provider\BrightcoveEmbedProvider;
use PHPUnit\Framework\TestCase;

final class BrightcoveEmbedProviderTest extends TestCase
{
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
        self::assertTrue($this->provider->matches(self::AL_JAZEERA));
        self::assertSame(self::AL_JAZEERA, $this->provider->normalize(self::AL_JAZEERA));
    }

    public function testKeepsOnlyTheVideoIdOfTheQuery(): void
    {
        self::assertSame(
            self::AL_JAZEERA,
            $this->provider->normalize(self::AL_JAZEERA . '&autoplay=1&muted=true'),
        );
    }

    public function testKeepsThePlayerIdVerbatim(): void
    {
        $url = 'https://players.brightcove.net/123/AbC_x-1/index.html?videoId=456';

        self::assertSame($url, $this->provider->normalize($url));
    }

    public function testRejectsAPlayerUrlWithoutAVideoId(): void
    {
        self::assertFalse($this->provider->matches('https://players.brightcove.net/123/abc_default/index.html'));
    }

    public function testRejectsANonNumericVideoId(): void
    {
        $url = 'https://players.brightcove.net/123/abc_default/index.html?videoId=x';
        self::assertFalse($this->provider->matches($url));
    }

    public function testRejectsALookAlikeHost(): void
    {
        $lookAlikeHost = 'https://players.brightcove.net.evil.test/123/abc_default/index.html?videoId=1';
        $httpScheme = 'http://players.brightcove.net/123/abc_default/index.html?videoId=1';

        self::assertFalse($this->provider->matches($lookAlikeHost));
        self::assertFalse($this->provider->matches($httpScheme));
    }

    public function testOffersNoPosterAndAGenericLabel(): void
    {
        self::assertNull($this->provider->poster(self::AL_JAZEERA));
        self::assertSame('Watch the video', $this->provider->label());
    }
}
