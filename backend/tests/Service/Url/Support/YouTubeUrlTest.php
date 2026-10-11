<?php

declare(strict_types=1);

namespace App\Tests\Service\Url\Support;

use App\Service\Url\Support\YouTubeUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class YouTubeUrlTest extends TestCase
{
    #[DataProvider('videoUrls')]
    public function testReadsTheVideoOfEverySpelling(string $url): void
    {
        $video = YouTubeUrl::video($url);

        self::assertNotNull($video);
        self::assertSame('M1j_uRqKMKI', $video->id);
        self::assertFalse($video->isShort);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function videoUrls(): iterable
    {
        yield 'watch page' => ['https://www.youtube.com/watch?v=M1j_uRqKMKI&t=30'];
        yield 'mobile watch page' => ['https://m.youtube.com/watch?v=M1j_uRqKMKI'];
        yield 'upper-case host' => ['https://WWW.YouTube.com/watch?v=M1j_uRqKMKI'];
        yield 'embed path' => ['https://www.youtube.com/embed/M1j_uRqKMKI?si=abcdefgh'];
        yield 'legacy v path' => ['https://youtube.com/v/M1j_uRqKMKI'];
        yield 'nocookie player' => ['https://www.youtube-nocookie.com/embed/M1j_uRqKMKI'];
        yield 'share link' => ['https://youtu.be/M1j_uRqKMKI'];
        yield 'share link on www' => ['https://www.youtu.be/M1j_uRqKMKI'];
        yield 'share link with a trailing slash' => ['https://youtu.be/M1j_uRqKMKI/'];
        yield 'share link with a query' => ['https://YouTu.be/M1j_uRqKMKI?si=abc&list=PL1'];
    }

    #[DataProvider('shortUrls')]
    public function testReadsAShort(string $url): void
    {
        $video = YouTubeUrl::video($url);

        self::assertNotNull($video);
        self::assertSame('GhUuOxrCato', $video->id);
        self::assertTrue($video->isShort);
        self::assertTrue(YouTubeUrl::isShort($url));
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
    public function testReadsNoVideoFromAnyOtherUrl(?string $url): void
    {
        self::assertNull(YouTubeUrl::video($url));
        self::assertFalse(YouTubeUrl::isShort($url));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function otherUrls(): iterable
    {
        yield 'shorts without an id' => ['https://www.youtube.com/shorts/'];
        yield 'id too short' => ['https://www.youtube.com/shorts/tooShort'];
        yield 'id too long' => ['https://www.youtube.com/shorts/GhUuOxrCato1'];
        yield 'nested shorts path' => ['https://www.youtube.com/shorts/GhUuOxrCato/extra'];
        yield 'prefixed shorts path' => ['https://www.youtube.com/channel/shorts/GhUuOxrCato'];
        yield 'shorts path on the share-link host' => ['https://youtu.be/shorts/GhUuOxrCato'];
        yield 'embed path on the share-link host' => ['https://youtu.be/embed/GhUuOxrCato'];
        yield 'share link with a sub-path' => ['https://youtu.be/GhUuOxrCato/extra'];
        yield 'share link with a trailing newline' => ["https://youtu.be/GhUuOxrCato\n"];
        yield 'watch query on the share-link host' => ['https://youtu.be/watch?v=GhUuOxrCato'];
        yield 'watch query on the nocookie host' => ['https://www.youtube-nocookie.com/watch?v=GhUuOxrCato'];
        yield 'watch page with a malformed id' => ['https://www.youtube.com/watch?v=GhUuOxrCat'];
        yield 'watch id given as an array' => ['https://www.youtube.com/watch?v[]=GhUuOxrCato'];
        yield 'channel page' => ['https://www.youtube.com/@veritasium'];
        yield 'other host' => ['https://example.com/shorts/GhUuOxrCato'];
        yield 'look-alike host' => ['https://youtube.com.evil.test/embed/GhUuOxrCato'];
        yield 'not a url' => ['shorts/GhUuOxrCato'];
        yield 'no url' => [null];
    }

    public function testAWatchPageIsNoShort(): void
    {
        self::assertFalse(YouTubeUrl::isShort('https://www.youtube.com/watch?v=GhUuOxrCato'));
    }

    public function testAShortsPlayerUrlIsTheNocookiePortraitPlayer(): void
    {
        self::assertSame(
            'https://www.youtube-nocookie.com/embed/GhUuOxrCato#shorts',
            YouTubeUrl::shortPlayerUrl('https://m.youtube.com/shorts/GhUuOxrCato?feature=share'),
        );
    }

    public function testAnyOtherUrlHasNoShortPlayerUrl(): void
    {
        self::assertNull(YouTubeUrl::shortPlayerUrl('https://www.youtube.com/watch?v=GhUuOxrCato'));
        self::assertNull(YouTubeUrl::shortPlayerUrl(null));
    }

    #[DataProvider('shareLinks')]
    public function testRecognisesAShareLink(string $url, bool $isShareLink): void
    {
        self::assertSame($isShareLink, YouTubeUrl::isShareLink($url));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function shareLinks(): iterable
    {
        yield 'share link' => ['https://youtu.be/EeS-cBgIoxI?list=PL1', true];
        yield 'share link on www' => ['https://www.youtu.be/EeS-cBgIoxI', true];
        yield 'share-link host without a video' => ['https://youtu.be/playlist?list=PL1', false];
        yield 'watch page' => ['https://www.youtube.com/watch?v=EeS-cBgIoxI', false];
        yield 'video-shaped path on another host' => ['https://vimeo.com/EeS-cBgIoxI', false];
    }

    #[DataProvider('youTubeComHosts')]
    public function testRecognisesAYouTubeComHost(string $host): void
    {
        self::assertTrue(YouTubeUrl::isComHost($host));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function youTubeComHosts(): iterable
    {
        yield 'bare' => ['youtube.com'];
        yield 'www' => ['www.youtube.com'];
        yield 'mobile' => ['m.youtube.com'];
        yield 'upper case' => ['WWW.YouTube.com'];
    }

    #[DataProvider('otherHosts')]
    public function testRejectsAnyOtherHostAsAYouTubeComHost(string $host): void
    {
        self::assertFalse(YouTubeUrl::isComHost($host));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherHosts(): iterable
    {
        yield 'share link' => ['youtu.be'];
        yield 'nocookie' => ['www.youtube-nocookie.com'];
        yield 'look-alike' => ['notyoutube.com'];
    }

    public function testAcceptsAVideoId(): void
    {
        self::assertTrue(YouTubeUrl::isVideoId('EeS-cBgIoxI'));
    }

    #[DataProvider('malformedIds')]
    public function testRejectsAMalformedVideoId(string $candidate): void
    {
        self::assertFalse(YouTubeUrl::isVideoId($candidate));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedIds(): iterable
    {
        yield 'too short' => ['EeS-cBgIox'];
        yield 'too long' => ['EeS-cBgIoxIx'];
        yield 'a trailing newline' => ["EeS-cBgIoxI\n"];
    }

    public function testEverySourceHostIsListed(): void
    {
        self::assertSame(
            [
                'youtube.com', 'www.youtube.com', 'm.youtube.com',
                'youtube-nocookie.com', 'www.youtube-nocookie.com',
                'youtu.be', 'www.youtu.be',
            ],
            YouTubeUrl::HOSTS,
        );
    }
}
