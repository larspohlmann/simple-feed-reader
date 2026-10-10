<?php

declare(strict_types=1);

namespace App\Tests\Service\Discovery\Support;

use App\Service\Discovery\Support\YouTubePlaylistLink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class YouTubePlaylistLinkTest extends TestCase
{
    private const string LIST = 'PLFs4vir_WsTwEd-nJgVJCZPNL3HALHHpF';

    #[DataProvider('playlistLinks')]
    public function testReadsThePlaylistIdOfAPlaylistLink(string $url): void
    {
        self::assertSame(self::LIST, YouTubePlaylistLink::listId($url));
    }

    /** @return iterable<string, array{string}> */
    public static function playlistLinks(): iterable
    {
        yield 'the playlist page' => ['https://www.youtube.com/playlist?list=' . self::LIST];
        yield 'the bare host' => ['https://youtube.com/playlist?list=' . self::LIST];
        yield 'the mobile host' => ['https://m.youtube.com/playlist?list=' . self::LIST];
        yield 'a mixed-case host' => ['https://www.YouTube.com/playlist?list=' . self::LIST];
        yield 'a trailing slash' => ['https://www.youtube.com/playlist/?list=' . self::LIST];
        yield 'a video watched inside the playlist' => [
            'https://www.youtube.com/watch?v=EeS-cBgIoxI&list=' . self::LIST . '&index=3',
        ];
        yield 'a share tracking parameter' => ['https://www.youtube.com/playlist?list=' . self::LIST . '&si=abc'];
    }

    #[DataProvider('otherLinks')]
    public function testFindsNoPlaylistInAnythingElse(string $url): void
    {
        self::assertNull(YouTubePlaylistLink::listId($url));
    }

    /** @return iterable<string, array{string}> */
    public static function otherLinks(): iterable
    {
        yield 'an auto-generated mix' => ['https://www.youtube.com/watch?v=EeS-cBgIoxI&list=RDEeS-cBgIoxI'];
        yield 'a video without a playlist' => ['https://www.youtube.com/watch?v=EeS-cBgIoxI'];
        yield 'a playlist page without a list' => ['https://www.youtube.com/playlist'];
        yield 'an empty list' => ['https://www.youtube.com/playlist?list='];
        yield 'a list with a forbidden character' => ['https://www.youtube.com/playlist?list=PL%27x'];
        yield 'a list given twice as an array' => ['https://www.youtube.com/playlist?list[]=' . self::LIST];
        yield 'a channel page carrying a list' => ['https://www.youtube.com/@veritasium?list=' . self::LIST];
        yield 'a look-alike host' => ['https://www.youtube.com.evil.example/playlist?list=' . self::LIST];
        yield 'another host' => ['https://vimeo.com/playlist?list=' . self::LIST];
        yield 'text that is not a URL' => ['not a url'];
    }
}
