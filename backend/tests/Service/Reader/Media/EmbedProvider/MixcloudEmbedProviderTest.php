<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\EmbedProvider;

use App\Service\Reader\Media\EmbedProvider\MixcloudEmbedProvider;
use App\Service\Reader\Media\Model\EmbedKind;
use App\Service\Reader\Media\Model\EmbedShape;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MixcloudEmbedProviderTest extends TestCase
{
    use MatchesEmbedFrames;

    private const string WIDGET = 'https://player-widget.mixcloud.com/widget/iframe/?feed=';
    private const string SHOW_WIDGET = self::WIDGET . '%2Fjohndigweed%2Ftransitions-1154%2F';

    private MixcloudEmbedProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new MixcloudEmbedProvider();
    }

    /** @return iterable<string, array{string}> */
    public static function spellingsOfOneShow(): iterable
    {
        yield 'the show page' => ['https://www.mixcloud.com/johndigweed/transitions-1154/'];
        yield 'the show page with a query' => [
            'https://www.mixcloud.com/johndigweed/transitions-1154/?utm_source=x',
        ];
        yield 'the canonical widget' => [self::SHOW_WIDGET];
        yield 'a widget with autoplay and chrome flags' => [
            self::WIDGET . '%2Fjohndigweed%2Ftransitions-1154%2F&hide_cover=1&autoplay=1&light=1',
        ];
        yield 'a widget without the trailing slash' => [
            'https://player-widget.mixcloud.com/widget/iframe?feed=%2Fjohndigweed%2Ftransitions-1154%2F',
        ];
        yield 'a widget fed the whole show url, as the oEmbed iframe is' => [
            self::WIDGET . 'https%3A%2F%2Fwww.mixcloud.com%2Fjohndigweed%2Ftransitions-1154%2F&hide_cover=1',
        ];
    }

    #[DataProvider('spellingsOfOneShow')]
    public function testEverySpellingOfAShowBecomesTheCanonicalWidget(string $url): void
    {
        self::assertSame(self::SHOW_WIDGET, $this->provider->target($url)?->url);
    }

    /** Mixcloud's own oEmbed encodes the key once more, so an encoded slug's `%` arrives as `%25`. */
    public function testEncodesAnEncodedSlugOnceMoreAsMixcloudItselfDoes(): void
    {
        self::assertSame(
            self::WIDGET . '%2Fworldwidefm%2Fgilles-peterson-w-moses-boyd-flo-real-mel%25C3%25A9-29-10-26%2F',
            $this->provider->target(
                'https://www.mixcloud.com/worldwidefm/gilles-peterson-w-moses-boyd-flo-real-mel%C3%A9-29-10-26/',
            )?->url,
        );
    }

    public function testHasNoPosterSoItFallsBackToALabel(): void
    {
        $target = $this->provider->target(self::SHOW_WIDGET);

        self::assertNotNull($target);
        self::assertNull($target->posterUrl);
        self::assertSame('Listen on Mixcloud', $target->label);
    }

    /** @return iterable<string, array{string}> */
    public static function urlsThatAreNoShow(): iterable
    {
        yield 'a profile' => ['https://www.mixcloud.com/johndigweed/'];
        yield 'a profile tab' => ['https://www.mixcloud.com/johndigweed/uploads/'];
        yield 'a genre' => ['https://www.mixcloud.com/genres/techno/'];
        yield 'a widget without a feed' => [self::WIDGET];
        yield 'a widget fed a profile' => [self::WIDGET . '%2Fjohndigweed%2F'];
        yield 'a widget on another path' => ['https://player-widget.mixcloud.com/other/?feed=%2Fdj%2Fshow%2F'];
        yield 'the API host' => ['https://api.mixcloud.com/johndigweed/transitions-1154/'];
        yield 'another host' => ['https://w.soundcloud.com/player/?url=x'];
        yield 'a look-alike host' => [
            'https://player-widget.mixcloud.com.evil.test/widget/iframe/?feed=%2Fdj%2Fshow%2F',
        ];
    }

    #[DataProvider('urlsThatAreNoShow')]
    public function testRejectsAUrlThatPlaysNoShow(string $url): void
    {
        self::assertNull($this->provider->target($url));
    }

    public function testIsAnAudioPlayerInAFixedHeightStrip(): void
    {
        $frame = self::onlyFrame($this->provider);

        self::assertSame(EmbedKind::Audio, $frame->kind);
        self::assertSame(EmbedShape::Strip, $frame->shape);
    }

    public function testTheFrameTakesTheCanonicalWidgetOfAnEncodedSlug(): void
    {
        $target = $this->provider->target('https://www.mixcloud.com/NTSRadio/ma%C3%AF-linh-invites-tasya-kudryk/');
        self::assertNotNull($target);

        self::assertSame(EmbedShape::Strip, self::frameMatching($this->provider->frames(), $target->url)->shape);
    }

    public function testTheFrameRefusesAWidgetWithFlags(): void
    {
        self::assertSame([], self::framesMatching($this->provider->frames(), self::SHOW_WIDGET . '&autoplay=1'));
    }

    public function testClaimsTheWidgetHost(): void
    {
        self::assertSame(['player-widget.mixcloud.com'], $this->provider->sourceHosts());
    }
}
