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
    private function subscriptionTo(Feed $feed): Subscription
    {
        $now = new \DateTimeImmutable('2026-02-03T04:05:06Z');

        return new Subscription(new User('u@example.com', $now), $feed, $now);
    }

    public function testFlattensAnHtmlDescriptionToPlainText(): void
    {
        $feed = new Feed('https://example.com/feed.xml');
        $feed->setDescription('<p>One</p><p>Two</p>');

        self::assertSame('One Two', SubscriptionJson::one($this->subscriptionTo($feed))['description']);
    }

    public function testCapsALongDescription(): void
    {
        // 3-byte-per-character text: a byte-based substr(…, 0, 1000) would cut
        // roughly 333 characters plus a broken trailing byte sequence, not the
        // 1000-character prefix mb_substr produces. That divergence is the
        // point — an ASCII fixture cannot tell mb_substr and substr apart.
        $feed = new Feed('https://example.com/feed.xml');
        $feed->setDescription(str_repeat('あ', 1200));

        $description = SubscriptionJson::one($this->subscriptionTo($feed))['description'];

        self::assertIsString($description);
        self::assertSame(1000, mb_strlen($description));
        self::assertSame(str_repeat('あ', 1000), $description);
        self::assertTrue(mb_check_encoding($description, 'UTF-8'));
    }

    public function testAMissingDescriptionStaysNull(): void
    {
        $feed = new Feed('https://example.com/feed.xml');

        self::assertNull(SubscriptionJson::one($this->subscriptionTo($feed))['description']);
    }

    public function testADescriptionOfOnlyMarkupBecomesNull(): void
    {
        $feed = new Feed('https://example.com/feed.xml');
        $feed->setDescription('<p></p>');

        self::assertNull(SubscriptionJson::one($this->subscriptionTo($feed))['description']);
    }

    public function testADescriptionOfOnlyPunctuationBecomesNull(): void
    {
        $feed = new Feed('https://example.com/feed.xml');
        // Deutschlandfunk really does describe itself as a lone ">".
        $feed->setDescription('>');

        self::assertNull(SubscriptionJson::one($this->subscriptionTo($feed))['description']);
    }

    public function testADescriptionOfOneRealWordSurvives(): void
    {
        $feed = new Feed('https://example.com/feed.xml');
        $feed->setDescription('Nachrichten.');

        self::assertSame('Nachrichten.', SubscriptionJson::one($this->subscriptionTo($feed))['description']);
    }

    public function testFallsBackToTheFeedsOwnOriginWhenTheFeedPublishesNoLink(): void
    {
        // Just under half a real library publishes no channel <link>; without
        // this the intro block offers those feeds no homepage at all.
        $feed = new Feed('https://example.com/rss/all.xml');

        self::assertSame('https://example.com', SubscriptionJson::one($this->subscriptionTo($feed))['siteUrl']);
    }

    public function testPrefersTheLinkTheFeedPublishedOverTheDerivedOrigin(): void
    {
        $feed = new Feed('https://feeds.example.com/rss/all.xml');
        $feed->setSiteUrl('https://www.example.org/');

        self::assertSame('https://www.example.org/', SubscriptionJson::one($this->subscriptionTo($feed))['siteUrl']);
    }

    public function testIgnoresAPublishedLinkThatNamesNoPublicHost(): void
    {
        // ZDFheute really does publish an internal Kubernetes service name.
        $feed = new Feed('https://www.zdfheute.de/rss/zdf/nachrichten');
        $feed->setSiteUrl('https://ssi-proxy-backends.default.svc.futura-prod/rss/zdf/nachrichten');

        self::assertSame('https://www.zdfheute.de', SubscriptionJson::one($this->subscriptionTo($feed))['siteUrl']);
    }

    public function testIgnoresASingleLabelPublishedLink(): void
    {
        $feed = new Feed('https://www.example.com/feed.xml');
        $feed->setSiteUrl('http://localhost/');

        self::assertSame('https://www.example.com', SubscriptionJson::one($this->subscriptionTo($feed))['siteUrl']);
    }

    public function testIgnoresAPublishedLinkThatIsABareAddress(): void
    {
        $feed = new Feed('https://www.example.com/feed.xml');
        $feed->setSiteUrl('http://192.168.1.10/');

        self::assertSame('https://www.example.com', SubscriptionJson::one($this->subscriptionTo($feed))['siteUrl']);
    }

    public function testKeepsAPublishedLinkOnAPunycodeDomain(): void
    {
        $feed = new Feed('https://feeds.example.com/feed.xml');
        $feed->setSiteUrl('https://example.xn--p1ai/');

        self::assertSame('https://example.xn--p1ai/', SubscriptionJson::one($this->subscriptionTo($feed))['siteUrl']);
    }

    public function testIgnoresAPublishedLinkThatPointsAtTheFeedItself(): void
    {
        // Telepolis points its channel <link> at the feed document; offering it
        // as "the website" sends the reader to raw XML.
        $feed = new Feed('https://www.telepolis.de/feed.xml');
        $feed->setSiteUrl('https://www.telepolis.de/feed.xml');

        self::assertSame('https://www.telepolis.de', SubscriptionJson::one($this->subscriptionTo($feed))['siteUrl']);
    }

    public function testSendsTheFeedImage(): void
    {
        $feed = new Feed('https://example.com/feed.xml');
        $feed->setImageUrl('https://example.com/logo.png');

        self::assertSame(
            'https://example.com/logo.png',
            SubscriptionJson::one($this->subscriptionTo($feed))['imageUrl'],
        );
    }

    public function testShapeUsesCustomTitleThenFeedTitleThenUrl(): void
    {
        $now = new \DateTimeImmutable('2026-02-03T04:05:06Z');
        $user = new User('u@example.com', $now);
        $feed = new Feed('https://example.com/feed.xml');
        $feed->setTitle('Example Feed');
        $feed->setSiteUrl('https://example.com');
        $feed->setFaviconUrl('https://example.com/favicon.ico');
        $feed->setLastFetchedAt(new \DateTimeImmutable('2026-02-04T10:11:12Z'));
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
        // When the feed was last successfully fetched — powers the header's
        // "Last refreshed" hint for a single-feed selection. Null until fetched.
        self::assertSame('2026-02-04T10:11:12+00:00', $shape['lastFetchedAt']);
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

    public function testSerialisesTheExclusionFlags(): void
    {
        $now = new \DateTimeImmutable('2026-02-03T04:05:06Z');
        $user = new User('u@example.com', $now);
        $feed = new Feed('https://example.com/feed.xml');
        $sub = new Subscription($user, $feed, $now);
        $sub->setIncludeInAllItems(false);
        $sub->setIncludeInForYou(true);

        $shape = SubscriptionJson::one($sub);

        self::assertFalse($shape['includeInAllItems']);
        self::assertTrue($shape['includeInForYou']);
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
        // A never-fetched feed reports a null last-refreshed time.
        self::assertNull($shape['lastFetchedAt']);
    }
}
