<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Http\SubscriptionJson;
use PHPUnit\Framework\TestCase;

final class SubscriptionJsonSavedTest extends TestCase
{
    public function testASavedFeedOfANetworkIsMarkedSavedWithoutAnAccount(): void
    {
        $json = SubscriptionJson::one($this->subscriptionTo('saved:4:mastodon', SocialNetwork::Mastodon));

        self::assertSame('saved', $json['sourceFormat']);
        self::assertSame(['network' => 'mastodon', 'accountId' => null, 'kind' => 'saved'], $json['social']);
    }

    public function testSavedFromFeedsIsNoSocialFeed(): void
    {
        self::assertNull(SubscriptionJson::one($this->subscriptionTo('saved:4', null))['social']);
    }

    private function subscriptionTo(string $url, ?SocialNetwork $network): Subscription
    {
        $feed = new Feed($url);
        $feed->setSourceFormat(SourceFormat::SAVED);
        $feed->classifyAs($network);

        return new Subscription(
            new User('json@example.test', new \DateTimeImmutable('2026-10-17')),
            $feed,
            new \DateTimeImmutable('2026-10-17'),
        );
    }
}
