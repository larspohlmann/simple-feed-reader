<?php

declare(strict_types=1);

namespace App\Tests\Service\Subscription;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\Subscription\Model\InclusionChangeModel;
use App\Service\Subscription\SubscriptionInclusions;
use PHPUnit\Framework\TestCase;

final class SubscriptionInclusionsTest extends TestCase
{
    public function testEachFlagIsWrittenToItsOwnField(): void
    {
        $subscription = $this->subscription();

        (new SubscriptionInclusions())->apply($subscription, new InclusionChangeModel(false, true, false));

        self::assertFalse($subscription->isIncludeInAllItems());
        self::assertTrue($subscription->isIncludeInForYou());
        self::assertFalse($subscription->isIncludeInSavedSearches());
    }

    public function testANullFlagLeavesItsFieldAlone(): void
    {
        $subscription = $this->subscription();
        $subscription->setIncludeInAllItems(false);
        $subscription->setIncludeInForYou(false);
        $subscription->setIncludeInSavedSearches(false);

        (new SubscriptionInclusions())->apply($subscription, new InclusionChangeModel());

        self::assertFalse($subscription->isIncludeInAllItems());
        self::assertFalse($subscription->isIncludeInForYou());
        self::assertFalse($subscription->isIncludeInSavedSearches());
    }

    public function testOnlyTheCarriedFlagChanges(): void
    {
        $subscription = $this->subscription();

        (new SubscriptionInclusions())->apply($subscription, new InclusionChangeModel(includeInForYou: false));

        self::assertTrue($subscription->isIncludeInAllItems());
        self::assertFalse($subscription->isIncludeInForYou());
        self::assertTrue($subscription->isIncludeInSavedSearches());
    }

    private function subscription(): Subscription
    {
        $created = new \DateTimeImmutable('2026-07-01T00:00:00Z');

        return new Subscription(
            new User('inclusions@example.com', $created),
            new Feed('https://inclusions.example/feed.xml'),
            $created,
        );
    }
}
