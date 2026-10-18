<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Http\SubscriptionJson;
use App\Service\Subscription\Model\SubscriptionTalliesModel;
use App\Tests\DbTestCase;
use App\Tests\Support\SocialAccounts;

final class SubscriptionJsonListTest extends DbTestCase
{
    public function testEachSubscriptionCarriesItsCountsAndTheSurfaceTotalsFollow(): void
    {
        $user = new User('subscription-list@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($user);
        $counted = $this->subscription($user, 'https://example.com/counted.xml');
        $silent = $this->subscription($user, 'https://example.com/silent.xml');
        $tallies = new SubscriptionTalliesModel(
            [$counted->requireId() => 3],
            [$counted->requireId() => 40],
            ['favorites' => 8, 'kept' => 2, 'viewed' => 41],
        );

        $payload = SubscriptionJson::list([$counted, $silent], $tallies, []);

        self::assertSame(
            ['subscriptions', 'favoritesCount', 'keptCount', 'viewedCount', 'socialAccounts'],
            array_keys($payload),
        );
        self::assertSame(
            [SubscriptionJson::one($counted, 3, 40), SubscriptionJson::one($silent)],
            $payload['subscriptions'],
        );
        self::assertSame(8, $payload['favoritesCount']);
        self::assertSame(2, $payload['keptCount']);
        self::assertSame(41, $payload['viewedCount']);
    }

    public function testTheListNamesEachConnectedAccountInOrder(): void
    {
        $user = new User('subscription-list-social@example.com', new \DateTimeImmutable('2026-10-12T00:00:00Z'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $bluesky = SocialAccounts::blueskyOf($user);
        $mastodon = SocialAccounts::mastodonOf($user);
        $this->entityManager->persist($bluesky);
        $this->entityManager->persist($mastodon);
        $this->entityManager->flush();

        $payload = SubscriptionJson::list(
            [],
            new SubscriptionTalliesModel([], [], ['favorites' => 0, 'kept' => 0, 'viewed' => 0]),
            [$bluesky, $mastodon],
        );

        self::assertSame(
            [
                [
                    'id' => $bluesky->requireId(),
                    'network' => 'bluesky',
                    'handle' => '@alice.bsky.social',
                    'status' => 'connected',
                ],
                [
                    'id' => $mastodon->requireId(),
                    'network' => 'mastodon',
                    'handle' => '@alice@mastodon.example',
                    'status' => 'connected',
                ],
            ],
            $payload['socialAccounts'],
        );
    }

    public function testTheListReportsAnAccountThatNeedsReconnecting(): void
    {
        $user = new User('subscription-list-reconnect@example.com', new \DateTimeImmutable('2026-10-12T00:00:00Z'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $bluesky = SocialAccounts::blueskyOf($user);
        $bluesky->markNeedsReconnect();
        $this->entityManager->persist($bluesky);
        $this->entityManager->flush();

        $payload = SubscriptionJson::list(
            [],
            new SubscriptionTalliesModel([], [], ['favorites' => 0, 'kept' => 0, 'viewed' => 0]),
            [$bluesky],
        );

        self::assertSame(
            [
                [
                    'id' => $bluesky->requireId(),
                    'network' => 'bluesky',
                    'handle' => '@alice.bsky.social',
                    'status' => 'needs_reconnect',
                ],
            ],
            $payload['socialAccounts'],
        );
    }

    private function subscription(User $user, string $url): Subscription
    {
        $feed = new Feed($url);
        $this->entityManager->persist($feed);
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();

        return $subscription;
    }
}
