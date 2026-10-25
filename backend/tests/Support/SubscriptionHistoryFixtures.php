<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\SubscriptionDay;
use App\Entity\User;
use App\Repository\SubscriptionDayRepository;
use App\Service\Retention\RetentionSettings;
use Symfony\Component\Clock\MockClock;

/** For a DbTestCase: subscriptions, arrivals and the history services, with now pinned to a UTC instant. */
trait SubscriptionHistoryFixtures
{
    private function historyWiring(string $now): SubscriptionHistoryWiring
    {
        $clock = new MockClock($now, 'UTC');

        return new SubscriptionHistoryWiring($this->entityManager, $this->retentionSettings(), $clock);
    }

    private function retentionSettings(): RetentionSettings
    {
        $settings = self::getContainer()->get(RetentionSettings::class);
        self::assertInstanceOf(RetentionSettings::class, $settings);

        return $settings;
    }

    private function storedDays(): SubscriptionDayRepository
    {
        return $this->entityManager->getRepository(SubscriptionDay::class);
    }

    private function subscribe(User $user, Feed $feed, string $subscribedAt): Subscription
    {
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable($subscribedAt));
        $this->entityManager->persist($user);
        $this->entityManager->persist($feed);
        $this->entityManager->persist($subscription);

        return $subscription;
    }

    private function subscriptionOfNewUser(string $email, string $subscribedAt, ?string $feedUrl = null): Subscription
    {
        return $this->subscribe(
            new User($email, new \DateTimeImmutable('2026-01-01 00:00:00')),
            new Feed($feedUrl ?? 'https://example.com/' . $email . '.xml'),
            $subscribedAt,
        );
    }

    private function arrival(Feed $feed, string $guid, string $createdAt): Entry
    {
        $created = new \DateTimeImmutable($createdAt);
        $entry = new Entry($feed, $guid, null, $guid, $created, $created);
        $this->entityManager->persist($entry);

        return $entry;
    }
}
