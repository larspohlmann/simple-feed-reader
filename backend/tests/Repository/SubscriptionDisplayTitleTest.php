<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Repository\SubscriptionDisplayTitle;
use PHPUnit\Framework\TestCase;

final class SubscriptionDisplayTitleTest extends TestCase
{
    private const string SEARCH_URL = 'social:bluesky:12:search:feed reader';
    private const string FEED_URL = 'https://heise.example/feed';
    private const string LIST_URL = 'social:bluesky:12:list:at://did:plc:bob/app.bsky.graph.list/3l1';

    public function testACustomTitleWinsThenTheFeedTitleThenTheUrl(): void
    {
        self::assertSame('Mine', SubscriptionDisplayTitle::from('Mine', 'Heise', self::FEED_URL));
        self::assertSame('Heise', SubscriptionDisplayTitle::from(null, 'Heise', self::FEED_URL));
        self::assertSame(self::FEED_URL, SubscriptionDisplayTitle::from('', '', self::FEED_URL));
    }

    public function testAnUnrenamedSearchIsTitledByItsCurrentQueryWhateverItsStoredTitle(): void
    {
        self::assertSame('feed reader', SubscriptionDisplayTitle::from(null, 'rss reader', self::SEARCH_URL));
        self::assertSame('feed reader', SubscriptionDisplayTitle::from('', null, self::SEARCH_URL));
    }

    public function testARenamedSearchKeepsItsCustomTitle(): void
    {
        self::assertSame('Readers', SubscriptionDisplayTitle::from('Readers', 'rss reader', self::SEARCH_URL));
    }

    public function testAnotherPrivateFeedKeepsItsStoredTitle(): void
    {
        self::assertSame('Writers', SubscriptionDisplayTitle::from(null, 'Writers', self::LIST_URL));
    }
}
