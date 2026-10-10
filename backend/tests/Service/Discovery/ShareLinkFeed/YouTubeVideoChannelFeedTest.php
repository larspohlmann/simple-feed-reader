<?php

declare(strict_types=1);

namespace App\Tests\Service\Discovery\ShareLinkFeed;

use App\Service\Discovery\ShareLinkFeed\YouTubeVideoChannelFeed;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Tests\Support\StubFeedFetcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class YouTubeVideoChannelFeedTest extends TestCase
{
    private const string WATCH_PAGE = 'https://www.youtube.com/watch?v=EeS-cBgIoxI';

    private const string CHANNEL_FEED =
        'https://www.youtube.com/feeds/videos.xml?channel_id=UCZpc-xP3njReG_r4Ur5a7mA';

    /** The player response of the watch page on 2026-10-10, trimmed to the field a resolver may read. */
    private const string WATCH_BODY = /** @lang HTML */ <<<'HTML'
        <html><body><script>var ytInitialPlayerResponse = {"microformat":{"playerMicroformatRenderer":
        {"externalChannelId":"UCZpc-xP3njReG_r4Ur5a7mA","ownerChannelName":"Milin Woolfelt"}}};</script></body></html>
        HTML;

    #[DataProvider('videoLinks')]
    public function testResolvesAVideoLinkToItsChannelFeed(string $enteredUrl): void
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willReturnBody(self::WATCH_PAGE, self::WATCH_BODY);

        self::assertSame(self::CHANNEL_FEED, (new YouTubeVideoChannelFeed($fetcher))->feedUrl($enteredUrl));
        self::assertSame([self::WATCH_PAGE], $fetcher->fetchedUrls);
    }

    /** @return iterable<string, array{string}> */
    public static function videoLinks(): iterable
    {
        yield 'the watch page' => [self::WATCH_PAGE];
        yield 'the bare host' => ['https://youtube.com/watch?v=EeS-cBgIoxI'];
        yield 'the mobile host' => ['https://m.youtube.com/watch?v=EeS-cBgIoxI'];
        yield 'a mixed-case host' => ['https://www.YouTube.com/watch?v=EeS-cBgIoxI'];
        yield 'a start time' => ['https://www.youtube.com/watch?t=42s&v=EeS-cBgIoxI'];
        yield 'an auto-generated mix' => ['https://www.youtube.com/watch?v=EeS-cBgIoxI&list=RDEeS-cBgIoxI'];
        yield 'the short link' => ['https://youtu.be/EeS-cBgIoxI'];
        yield 'the short link with a share id' => ['https://youtu.be/EeS-cBgIoxI?si=AbCdEf'];
        yield 'a mixed-case short-link host' => ['https://YouTu.be/EeS-cBgIoxI'];
        yield 'the short link with a trailing slash' => ['https://youtu.be/EeS-cBgIoxI/'];
        yield 'watched from watch later' => ['https://www.youtube.com/watch?v=EeS-cBgIoxI&list=WL'];
        yield 'a Short' => ['https://www.youtube.com/shorts/EeS-cBgIoxI'];
        yield 'a Short with a trailing slash' => ['https://www.youtube.com/shorts/EeS-cBgIoxI/'];
        yield 'a live stream' => ['https://www.youtube.com/live/EeS-cBgIoxI'];
    }

    #[DataProvider('otherLinks')]
    public function testLeavesEverythingElseAloneWithoutFetching(string $enteredUrl): void
    {
        $fetcher = new StubFeedFetcher();

        self::assertNull((new YouTubeVideoChannelFeed($fetcher))->feedUrl($enteredUrl));
        self::assertSame([], $fetcher->fetchedUrls);
    }

    /** @return iterable<string, array{string}> */
    public static function otherLinks(): iterable
    {
        yield 'a video inside a playlist' => [
            'https://www.youtube.com/watch?v=EeS-cBgIoxI&list=PLFs4vir_WsTwEd-nJgVJCZPNL3HALHHpF',
        ];
        yield 'a short link watched inside a playlist' => [
            'https://youtu.be/EeS-cBgIoxI?list=PLFs4vir_WsTwEd-nJgVJCZPNL3HALHHpF',
        ];
        yield 'a video id with a trailing newline' => ['https://www.youtube.com/watch?v=EeS-cBgIoxI%0A'];
        yield 'a playlist page' => ['https://www.youtube.com/playlist?list=PLFs4vir_WsTwEd-nJgVJCZPNL3HALHHpF'];
        yield 'a channel page' => ['https://www.youtube.com/@veritasium'];
        yield 'a watch page without a video' => ['https://www.youtube.com/watch'];
        yield 'a video id that is too short' => ['https://www.youtube.com/watch?v=EeS-cBgIox'];
        yield 'a video id that is too long' => ['https://www.youtube.com/watch?v=EeS-cBgIoxIx'];
        yield 'a video id given as an array' => ['https://www.youtube.com/watch?v[]=EeS-cBgIoxI'];
        yield 'the short-link host without a path' => ['https://youtu.be'];
        yield 'a short link with a sub-path' => ['https://youtu.be/EeS-cBgIoxI/extra'];
        yield 'a Short id that is too short' => ['https://www.youtube.com/shorts/EeS-cBgIox'];
        yield 'an embed on another host' => ['https://www.youtube-nocookie.com/embed/EeS-cBgIoxI'];
        yield 'a short-link path on a full host' => ['https://www.youtube.com/EeS-cBgIoxI'];
        yield 'a look-alike host' => ['https://www.youtube.com.evil.example/watch?v=EeS-cBgIoxI'];
        yield 'another host' => ['https://vimeo.com/watch?v=EeS-cBgIoxI'];
        yield 'text that is not a URL' => ['not a url'];
    }

    #[DataProvider('pagesWithoutAChannel')]
    public function testFallsThroughWhenThePageNamesNoChannel(string $body): void
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willReturnBody(self::WATCH_PAGE, $body);

        self::assertNull((new YouTubeVideoChannelFeed($fetcher))->feedUrl(self::WATCH_PAGE));
    }

    /** @return iterable<string, array{string}> */
    public static function pagesWithoutAChannel(): iterable
    {
        yield 'no channel id at all' => ['<html><body>Video unavailable</body></html>'];
        yield 'a channel id without the UC prefix' => ['{"externalChannelId":"XXZpc-xP3njReG_r4Ur5a7mA"}'];
        yield 'a channel id that is too short' => ['{"externalChannelId":"UCZpc-xP3njReG_r4Ur5a7m"}'];
        yield 'a channel id with a forbidden character' => ['{"externalChannelId":"UCZpc-xP3njReG_r4Ur5a7m\'"}'];
    }

    public function testFallsThroughWhenTheWatchPageCannotBeReached(): void
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willThrow(self::WATCH_PAGE, new FeedUnreachableException('x: HTTP 503', 503));

        self::assertNull((new YouTubeVideoChannelFeed($fetcher))->feedUrl(self::WATCH_PAGE));
    }
}
