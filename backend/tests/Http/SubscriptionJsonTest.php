<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Http\SubscriptionJson;
use PHPUnit\Framework\TestCase;

final class SubscriptionJsonTest extends TestCase
{
    public function testShapeUsesCustomTitleThenFeedTitleThenUrl(): void
    {
        $now = new \DateTimeImmutable('2026-02-03T04:05:06Z');
        $user = new User('u@example.com', $now);
        $feed = new Feed('https://example.com/feed.xml');
        $feed->setTitle('Example Feed');
        $feed->setSiteUrl('https://example.com');
        $feed->setFaviconUrl('https://example.com/favicon.ico');
        $sub = new Subscription($user, $feed, $now);
        $tag = new Tag($user, 'news');
        $tag->setColor('#ff8800');
        $sub->addTag($tag);

        $shape = SubscriptionJson::one($sub);

        self::assertSame('Example Feed', $shape['title']);
        self::assertNull($shape['customTitle']);
        // The shared feed's id — the handle the client needs to scope a refresh
        // (POST /api/refresh?feedId=) to a just-added feed. Null until persisted.
        self::assertArrayHasKey('feedId', $shape);
        self::assertSame($feed->getId(), $shape['feedId']);
        self::assertSame('https://example.com/feed.xml', $shape['feedUrl']);
        self::assertSame('https://example.com', $shape['siteUrl']);
        self::assertSame('https://example.com/favicon.ico', $shape['faviconUrl']);
        self::assertSame('active', $shape['status']);
        self::assertSame('xml', $shape['sourceFormat']);
        self::assertSame('2026-02-03T04:05:06+00:00', $shape['createdAt']);
        self::assertSame(0, $shape['position']);
        self::assertSame(
            [[
                'id' => $tag->getId(),
                'name' => 'news',
                'color' => '#ff8800',
                'icon' => null,
                'position' => 0,
            ]],
            $shape['tags'],
        );
    }

    public function testCustomTitleWinsAndFallsBackToUrl(): void
    {
        $now = new \DateTimeImmutable('2026-02-03T04:05:06Z');
        $user = new User('u@example.com', $now);
        $feed = new Feed('https://example.com/feed.xml'); // no title set
        $sub = new Subscription($user, $feed, $now);
        $sub->setCustomTitle('My Name');

        $shape = SubscriptionJson::one($sub);
        self::assertSame('My Name', $shape['title']);
        self::assertSame('My Name', $shape['customTitle']);

        $sub->setCustomTitle(null);
        $shape = SubscriptionJson::one($sub);
        self::assertSame('https://example.com/feed.xml', $shape['title']); // url fallback
    }
}
