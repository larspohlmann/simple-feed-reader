<?php

declare(strict_types=1);

namespace App\Tests\Service\Discovery\FeedOffer;

use App\Service\Discovery\FeedOffer\YouTubeVideoChannelFeed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class YouTubeVideoChannelFeedTest extends TestCase
{
    private const string WATCH_PAGE = 'https://www.youtube.com/watch?v=EeS-cBgIoxI';

    /** The player response of the watch page on 2026-10-10, trimmed to the fields an offer may read. */
    private const string WATCH_BODY = /** @lang HTML */ <<<'HTML'
        <html><body><script>var ytInitialPlayerResponse = {"microformat":{"playerMicroformatRenderer":
        {"externalChannelId":"UCZpc-xP3njReG_r4Ur5a7mA",
        "ownerChannelName":"Milin \"MW\" Woolfelt"}}};</script></body></html>
        HTML;

    public function testOffersTheChannelFeedOfTheVideoThePagePlays(): void
    {
        $candidate = (new YouTubeVideoChannelFeed())->offer(self::WATCH_BODY, self::WATCH_PAGE);

        self::assertNotNull($candidate);
        self::assertSame(
            'https://www.youtube.com/feeds/videos.xml?channel_id=UCZpc-xP3njReG_r4Ur5a7mA',
            $candidate->url,
        );
        self::assertSame('Milin "MW" Woolfelt', $candidate->title);
        self::assertSame('atom', $candidate->format);
    }

    #[DataProvider('youTubePages')]
    public function testReadsEveryYouTubeHost(string $pageUrl): void
    {
        self::assertNotNull((new YouTubeVideoChannelFeed())->offer(self::WATCH_BODY, $pageUrl));
    }

    /** @return iterable<string, array{string}> */
    public static function youTubePages(): iterable
    {
        yield 'the bare host' => ['https://youtube.com/watch?v=EeS-cBgIoxI'];
        yield 'the mobile host' => ['https://m.youtube.com/watch?v=EeS-cBgIoxI'];
        yield 'a mixed-case host' => ['https://www.YouTube.com/shorts/EeS-cBgIoxI'];
    }

    public function testAChannelWithoutANameIsStillOffered(): void
    {
        $candidate = (new YouTubeVideoChannelFeed())
            ->offer('{"externalChannelId":"UCZpc-xP3njReG_r4Ur5a7mA","ownerChannelName":""}', self::WATCH_PAGE);

        self::assertNotNull($candidate);
        self::assertNull($candidate->title);
    }

    #[DataProvider('pagesWithoutAChannel')]
    public function testOffersNothingWithoutAChannel(string $body, string $pageUrl): void
    {
        self::assertNull((new YouTubeVideoChannelFeed())->offer($body, $pageUrl));
    }

    /** @return iterable<string, array{string, string}> */
    public static function pagesWithoutAChannel(): iterable
    {
        yield 'no channel id at all' => ['<html><body>Video unavailable</body></html>', self::WATCH_PAGE];
        yield 'a channel id without the UC prefix' => [
            '{"externalChannelId":"XXZpc-xP3njReG_r4Ur5a7mA"}',
            self::WATCH_PAGE,
        ];
        yield 'a channel id that is too short' => ['{"externalChannelId":"UCZpc-xP3njReG_r4Ur5a7m"}', self::WATCH_PAGE];
        yield 'a page on another host' => [self::WATCH_BODY, 'https://example.com/watch?v=EeS-cBgIoxI'];
        yield 'a look-alike host' => [self::WATCH_BODY, 'https://www.youtube.com.evil.example/watch'];
    }
}
