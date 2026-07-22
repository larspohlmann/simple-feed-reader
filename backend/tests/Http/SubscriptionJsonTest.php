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
        $sub = new Subscription($user, $feed, $now);
        $tag = new Tag($user, 'news');
        $tag->setColor('#ff8800');
        $sub->addTag($tag);

        $shape = SubscriptionJson::one($sub);

        self::assertSame('Example Feed', $shape['title']);
        self::assertNull($shape['customTitle']);
        self::assertSame('https://example.com/feed.xml', $shape['feedUrl']);
        self::assertSame('https://example.com', $shape['siteUrl']);
        self::assertSame('active', $shape['status']);
        self::assertSame('2026-02-03T04:05:06+00:00', $shape['createdAt']);
        self::assertSame(
            [['id' => $tag->getId(), 'name' => 'news', 'color' => '#ff8800', 'icon' => null]],
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
