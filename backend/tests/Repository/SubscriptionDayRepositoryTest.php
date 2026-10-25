<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\SubscriptionDay;
use App\Entity\User;
use App\Repository\SubscriptionDayRepository;
use App\Service\SubscriptionHistory\Support\UtcDay;
use App\Tests\DbTestCase;

final class SubscriptionDayRepositoryTest extends DbTestCase
{
    private function repository(): SubscriptionDayRepository
    {
        $repository = $this->entityManager->getRepository(SubscriptionDay::class);
        self::assertInstanceOf(SubscriptionDayRepository::class, $repository);

        return $repository;
    }

    private function subscription(string $email): Subscription
    {
        $when = new \DateTimeImmutable('2026-09-01 00:00:00');
        $user = new User($email, $when);
        $feed = new Feed('https://example.com/' . $email . '.xml');
        $subscription = new Subscription($user, $feed, $when);
        $this->entityManager->persist($user);
        $this->entityManager->persist($feed);
        $this->entityManager->persist($subscription);

        return $subscription;
    }

    private function day(Subscription $subscription, string $day, int $arrived, int $read): void
    {
        $this->entityManager->persist(new SubscriptionDay($subscription, UtcDay::start($day), $arrived, $read));
    }

    public function testReturnsRowsOnOrAfterTheOldestDayForTheAskedSubscriptionsOnly(): void
    {
        $mine = $this->subscription('mine@example.com');
        $other = $this->subscription('other@example.com');
        $this->day($mine, '2026-10-09', 1, 1);
        $this->day($mine, '2026-10-10', 4, 2);
        $this->day($other, '2026-10-10', 9, 9);
        $this->entityManager->flush();

        $stored = $this->repository()->since([$mine->requireId()], '2026-10-10');

        self::assertFalse($stored->has($mine->requireId(), '2026-10-09'));
        self::assertTrue($stored->has($mine->requireId(), '2026-10-10'));
        self::assertSame(4, $stored->of($mine->requireId(), '2026-10-10')->arrived);
        self::assertSame(2, $stored->of($mine->requireId(), '2026-10-10')->read);
        self::assertFalse($stored->has($other->requireId(), '2026-10-10'));
    }

    public function testAStoredZeroIsPresentNotAbsent(): void
    {
        $mine = $this->subscription('zero@example.com');
        $this->day($mine, '2026-10-10', 0, 0);
        $this->entityManager->flush();

        $stored = $this->repository()->since([$mine->requireId()], '2026-10-01');

        self::assertTrue($stored->has($mine->requireId(), '2026-10-10'));
    }

    public function testNoSubscriptionsReadsNothing(): void
    {
        self::assertFalse($this->repository()->since([], '2026-10-01')->has(1, '2026-10-10'));
    }

    public function testDeletingTheSubscriptionDeletesItsHistory(): void
    {
        $mine = $this->subscription('cascade@example.com');
        $this->day($mine, '2026-10-10', 3, 0);
        $this->entityManager->flush();
        $subscriptionId = $mine->requireId();

        $this->entityManager->getConnection()
            ->executeStatement('DELETE FROM subscription WHERE id = ?', [$subscriptionId]);

        $stored = $this->repository()->since([$subscriptionId], '2026-10-01');
        self::assertFalse($stored->has($subscriptionId, '2026-10-10'));
    }
}
