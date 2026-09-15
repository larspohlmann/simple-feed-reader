<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Provider;

use App\Service\Reader\Media\Provider\VimeoEmbedProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VimeoEmbedProviderTest extends TestCase
{
    private VimeoEmbedProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new VimeoEmbedProvider();
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function embeddableUrls(): iterable
    {
        yield 'public id' => ['https://vimeo.com/1226652197', 'https://player.vimeo.com/video/1226652197'];
        yield 'trailing slash' => ['https://vimeo.com/1226652197/', 'https://player.vimeo.com/video/1226652197'];
        yield 'www host' => ['https://www.vimeo.com/1226652197', 'https://player.vimeo.com/video/1226652197'];
        yield 'unlisted hash' => [
            'https://vimeo.com/76979871/8272103f6e',
            'https://player.vimeo.com/video/76979871?h=8272103f6e',
        ];
        yield 'player url' => ['https://player.vimeo.com/video/76979871', 'https://player.vimeo.com/video/76979871'];
        yield 'player url trailing slash' => [
            'https://player.vimeo.com/video/76979871/',
            'https://player.vimeo.com/video/76979871',
        ];
        yield 'player url with hash' => [
            'https://player.vimeo.com/video/76979871?h=8272103f6e',
            'https://player.vimeo.com/video/76979871?h=8272103f6e',
        ];
        yield 'uppercase host' => ['https://VIMEO.COM/1226652197', 'https://player.vimeo.com/video/1226652197'];
        yield 'mixed-case player host' => [
            'https://Player.Vimeo.com/video/76979871',
            'https://player.vimeo.com/video/76979871',
        ];
    }

    #[DataProvider('embeddableUrls')]
    public function testNormalisesEverySpellingToOnePlayerEmbed(string $url, string $expected): void
    {
        self::assertTrue($this->provider->matches($url));
        self::assertSame($expected, $this->provider->normalize($url));
    }

    /** @return iterable<string, array{0: string}> */
    public static function unembeddableUrls(): iterable
    {
        yield 'profile name' => ['https://vimeo.com/staffpicks'];
        yield 'channel path' => ['https://vimeo.com/channels/staffpicks/12345'];
        yield 'player asset' => ['https://player.vimeo.com/api/player.js'];
        yield 'other host' => ['https://example.test/1226652197'];
        yield 'not https' => ['http://vimeo.com/1226652197'];
        yield 'player path with leading junk' => ['https://player.vimeo.com/embed/video/76979871'];
        yield 'player path with trailing junk' => ['https://player.vimeo.com/video/76979871/extra'];
    }

    #[DataProvider('unembeddableUrls')]
    public function testRefusesEverythingThatIsNotAVideoReference(string $url): void
    {
        self::assertFalse($this->provider->matches($url));
        self::assertNull($this->provider->normalize($url));
    }

    public function testOffersNoPosterAndNamesTheHost(): void
    {
        self::assertNull($this->provider->poster('https://vimeo.com/1226652197'));
        self::assertSame('Watch on Vimeo', $this->provider->label());
    }
}
