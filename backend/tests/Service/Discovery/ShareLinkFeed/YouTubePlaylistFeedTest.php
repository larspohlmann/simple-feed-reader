<?php

declare(strict_types=1);

namespace App\Tests\Service\Discovery\ShareLinkFeed;

use App\Service\Discovery\ShareLinkFeed\YouTubePlaylistFeed;
use PHPUnit\Framework\TestCase;

final class YouTubePlaylistFeedTest extends TestCase
{
    public function testResolvesAPlaylistLinkToThePlaylistFeed(): void
    {
        self::assertSame(
            'https://www.youtube.com/feeds/videos.xml?playlist_id=PLFs4vir_WsTwEd-nJgVJCZPNL3HALHHpF',
            (new YouTubePlaylistFeed())->feedUrl(
                'https://www.youtube.com/watch?v=EeS-cBgIoxI&list=PLFs4vir_WsTwEd-nJgVJCZPNL3HALHHpF',
            ),
        );
    }

    public function testLeavesALinkWithoutAPlaylistAlone(): void
    {
        self::assertNull((new YouTubePlaylistFeed())->feedUrl('https://www.youtube.com/watch?v=EeS-cBgIoxI'));
    }
}
