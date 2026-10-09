<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Support;

use App\Service\Reader\Media\Support\YouTubeShortUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class YouTubeShortUrlTest extends TestCase
{
    #[DataProvider('shortUrls')]
    public function testRecognisesAShort(string $url): void
    {
        self::assertTrue(YouTubeShortUrl::is($url));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function shortUrls(): iterable
    {
        yield 'www' => ['https://www.youtube.com/shorts/GhUuOxrCato'];
        yield 'bare host' => ['https://youtube.com/shorts/GhUuOxrCato'];
        yield 'mobile host' => ['https://m.youtube.com/shorts/GhUuOxrCato'];
        yield 'upper-case host' => ['https://WWW.YouTube.com/shorts/GhUuOxrCato'];
        yield 'trailing slash' => ['https://www.youtube.com/shorts/GhUuOxrCato/'];
        yield 'share query' => ['https://www.youtube.com/shorts/GhUuOxrCato?feature=share'];
    }

    #[DataProvider('otherUrls')]
    public function testFindsNoShortInAnyOtherUrl(?string $url): void
    {
        self::assertFalse(YouTubeShortUrl::is($url));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function otherUrls(): iterable
    {
        yield 'watch url' => ['https://www.youtube.com/watch?v=GhUuOxrCato'];
        yield 'no id' => ['https://www.youtube.com/shorts/'];
        yield 'id too short' => ['https://www.youtube.com/shorts/tooShort'];
        yield 'id too long' => ['https://www.youtube.com/shorts/GhUuOxrCato1'];
        yield 'nested path' => ['https://www.youtube.com/shorts/GhUuOxrCato/extra'];
        yield 'prefixed path' => ['https://www.youtube.com/channel/shorts/GhUuOxrCato'];
        yield 'other host' => ['https://example.com/shorts/GhUuOxrCato'];
        yield 'look-alike host' => ['https://notyoutube.com/shorts/GhUuOxrCato'];
        yield 'not a url' => ['shorts/GhUuOxrCato'];
        yield 'no url' => [null];
    }

    public function testReadsTheVideoIdOfAShortsPath(): void
    {
        self::assertSame('GhUuOxrCato', YouTubeShortUrl::videoId('M.YouTube.com', '/shorts/GhUuOxrCato/'));
    }

    public function testReadsNoVideoIdFromAWatchPath(): void
    {
        self::assertNull(YouTubeShortUrl::videoId('www.youtube.com', '/watch'));
    }

    public function testReadsNoVideoIdFromAShortsPathOnAnotherYouTubeHost(): void
    {
        self::assertNull(YouTubeShortUrl::videoId('youtu.be', '/shorts/GhUuOxrCato'));
    }
}
