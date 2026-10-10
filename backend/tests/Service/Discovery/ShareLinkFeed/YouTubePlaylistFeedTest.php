<?php

declare(strict_types=1);

namespace App\Tests\Service\Discovery\ShareLinkFeed;

use App\Service\Discovery\ShareLinkFeed\YouTubePlaylistFeed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class YouTubePlaylistFeedTest extends TestCase
{
    private const string LIST = 'PLFs4vir_WsTwEd-nJgVJCZPNL3HALHHpF';

    private const string FEED = 'https://www.youtube.com/feeds/videos.xml?playlist_id=' . self::LIST;

    #[DataProvider('playlistLinks')]
    public function testResolvesAPlaylistLinkToThePlaylistFeed(string $enteredUrl): void
    {
        self::assertSame(self::FEED, (new YouTubePlaylistFeed())->feedUrl($enteredUrl));
    }

    /** @return iterable<string, array{string}> */
    public static function playlistLinks(): iterable
    {
        yield 'the playlist page' => ['https://www.youtube.com/playlist?list=' . self::LIST];
        yield 'the bare host' => ['https://youtube.com/playlist?list=' . self::LIST];
        yield 'the mobile host' => ['https://m.youtube.com/playlist?list=' . self::LIST];
        yield 'a mixed-case host' => ['https://www.YouTube.com/playlist?list=' . self::LIST];
        yield 'a trailing slash' => ['https://www.youtube.com/playlist/?list=' . self::LIST];
        yield 'a share tracking parameter' => ['https://www.youtube.com/playlist?list=' . self::LIST . '&si=abc'];
        yield 'a video watched inside the playlist' => [
            'https://www.youtube.com/watch?v=EeS-cBgIoxI&list=' . self::LIST . '&index=3',
        ];
        yield 'a short link watched inside the playlist' => [
            'https://youtu.be/EeS-cBgIoxI?list=' . self::LIST . '&si=abc',
        ];
        yield 'a short link with a trailing slash' => ['https://youtu.be/EeS-cBgIoxI/?list=' . self::LIST];
        yield 'a mixed-case short-link host' => ['https://YouTu.be/EeS-cBgIoxI?list=' . self::LIST];
    }

    #[DataProvider('otherLinks')]
    public function testLeavesEverythingElseAlone(string $enteredUrl): void
    {
        self::assertNull((new YouTubePlaylistFeed())->feedUrl($enteredUrl));
    }

    /** @return iterable<string, array{string}> */
    public static function otherLinks(): iterable
    {
        yield 'a video without a playlist' => ['https://www.youtube.com/watch?v=EeS-cBgIoxI'];
        yield 'a playlist page without a list' => ['https://www.youtube.com/playlist'];
        yield 'an empty list' => ['https://www.youtube.com/playlist?list='];
        yield 'a list with a forbidden character' => ['https://www.youtube.com/playlist?list=PL%27x'];
        yield 'a list with a trailing newline' => ['https://www.youtube.com/playlist?list=' . self::LIST . '%0A'];
        yield 'a list given as an array' => ['https://www.youtube.com/playlist?list[]=' . self::LIST];
        yield 'a channel page carrying a list' => ['https://www.youtube.com/@veritasium?list=' . self::LIST];
        yield 'a short-link host without a video' => ['https://youtu.be/playlist?list=' . self::LIST];
        yield 'a short link with a sub-path' => ['https://youtu.be/EeS-cBgIoxI/extra?list=' . self::LIST];
        yield 'a look-alike host' => ['https://www.youtube.com.evil.example/playlist?list=' . self::LIST];
        yield 'a video-shaped path on another host' => ['https://vimeo.com/EeS-cBgIoxI?list=' . self::LIST];
        yield 'another host' => ['https://vimeo.com/playlist?list=' . self::LIST];
        yield 'text that is not a URL' => ['not a url'];
    }
}
