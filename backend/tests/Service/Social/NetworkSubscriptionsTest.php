<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Service\Social\NetworkSubscriptions;
use App\Tests\DbTestCase;
use App\Tests\Support\NetworkFeedSubscriptions;

final class NetworkSubscriptionsTest extends DbTestCase
{
    public function testANetworkHoldsItsPrivateAndProfileFeedsExcludedOnesIncluded(): void
    {
        $user = $this->user('network-owner@example.com');
        $home = $this->subscriptions()->subscribe(
            $user,
            NetworkFeedSubscriptions::blueskyHome(1),
            SourceFormat::BLUESKY_API,
        );
        $home->setIncludeInAllItems(false);
        $profile = $this->subscriptions()->subscribe($user, NetworkFeedSubscriptions::BLUESKY_PROFILE);
        $this->subscriptions()->subscribe($user, NetworkFeedSubscriptions::MASTODON_PROFILE);
        $this->subscriptions()->subscribe($user, NetworkFeedSubscriptions::PLAIN);
        $this->entityManager->flush();

        self::assertSame(
            [$home->requireId(), $profile->requireId()],
            $this->service()->idsOf($user->requireId(), SocialNetwork::Bluesky),
        );
    }

    public function testAnotherUsersFeedsOfTheNetworkAreNeverIncluded(): void
    {
        $user = $this->user('network-self@example.com');
        $own = $this->subscriptions()->subscribe($user, NetworkFeedSubscriptions::MASTODON_PROFILE);
        $stranger = $this->user('network-stranger@example.com');
        $this->subscriptions()->subscribe($stranger, 'https://fosstodon.org/@bob.rss');

        $found = $this->service()->of($user->requireId(), SocialNetwork::Mastodon);

        self::assertSame(
            [$own->requireId()],
            array_map(static fn (Subscription $subscription): int => $subscription->requireId(), $found),
        );
    }

    public function testANetworkWithoutFeedsHoldsNone(): void
    {
        $user = $this->user('network-empty@example.com');
        $this->subscriptions()->subscribe($user, NetworkFeedSubscriptions::PLAIN);

        self::assertSame([], $this->service()->idsOf($user->requireId(), SocialNetwork::Bluesky));
    }

    private function user(string $email): User
    {
        $user = new User($email, new \DateTimeImmutable('2026-10-01T00:00:00Z'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function subscriptions(): NetworkFeedSubscriptions
    {
        return new NetworkFeedSubscriptions($this->entityManager);
    }

    private function service(): NetworkSubscriptions
    {
        $service = self::getContainer()->get(NetworkSubscriptions::class);
        self::assertInstanceOf(NetworkSubscriptions::class, $service);

        return $service;
    }
}
