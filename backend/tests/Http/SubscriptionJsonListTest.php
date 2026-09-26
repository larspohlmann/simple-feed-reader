<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Http\SubscriptionJson;
use App\Service\Subscription\SubscriptionTallies;
use App\Tests\DbTestCase;

final class SubscriptionJsonListTest extends DbTestCase
{
    public function testEachSubscriptionCarriesItsCountsAndTheSurfaceTotalsFollow(): void
    {
        $user = new User('subscription-list@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($user);
        $counted = $this->subscription($user, 'https://example.com/counted.xml');
        $silent = $this->subscription($user, 'https://example.com/silent.xml');
        $tallies = new SubscriptionTallies(
            [$counted->requireId() => 3],
            [$counted->requireId() => 40],
            ['favorites' => 8, 'kept' => 2, 'viewed' => 41],
        );

        $payload = SubscriptionJson::list([$counted, $silent], $tallies);

        self::assertSame(['subscriptions', 'favoritesCount', 'keptCount', 'viewedCount'], array_keys($payload));
        self::assertSame(
            [SubscriptionJson::one($counted, 3, 40), SubscriptionJson::one($silent)],
            $payload['subscriptions'],
        );
        self::assertSame(8, $payload['favoritesCount']);
        self::assertSame(2, $payload['keptCount']);
        self::assertSame(41, $payload['viewedCount']);
    }

    private function subscription(User $user, string $url): Subscription
    {
        $feed = new Feed($url);
        $this->em->persist($feed);
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($subscription);
        $this->em->flush();

        return $subscription;
    }
}
