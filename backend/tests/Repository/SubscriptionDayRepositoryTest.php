<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Subscription;
use App\Entity\SubscriptionDay;
use App\Service\SubscriptionHistory\Support\UtcDay;
use App\Tests\DbTestCase;
use App\Tests\Support\SubscriptionHistoryFixtures;

final class SubscriptionDayRepositoryTest extends DbTestCase
{
    use SubscriptionHistoryFixtures;

    private function day(Subscription $subscription, string $day, int $arrived, int $read): void
    {
        $this->entityManager->persist(new SubscriptionDay($subscription, UtcDay::start($day), $arrived, $read));
    }

    public function testReturnsRowsOnOrAfterTheOldestDayForTheAskedSubscriptionsOnly(): void
    {
        $mine = $this->subscriptionOfNewUser('mine@example.com', '2026-09-01 00:00:00');
        $other = $this->subscriptionOfNewUser('other@example.com', '2026-09-01 00:00:00');
        $this->day($mine, '2026-10-09', 1, 1);
        $this->day($mine, '2026-10-10', 4, 2);
        $this->day($other, '2026-10-10', 9, 9);
        $this->entityManager->flush();

        $stored = $this->storedDays()->since([$mine->requireId()], '2026-10-10');

        self::assertFalse($stored->has($mine->requireId(), '2026-10-09'));
        self::assertTrue($stored->has($mine->requireId(), '2026-10-10'));
        self::assertSame(4, $stored->of($mine->requireId(), '2026-10-10')->arrived);
        self::assertSame(2, $stored->of($mine->requireId(), '2026-10-10')->read);
        self::assertFalse($stored->has($other->requireId(), '2026-10-10'));
    }

    public function testAStoredZeroIsPresentNotAbsent(): void
    {
        $mine = $this->subscriptionOfNewUser('zero@example.com', '2026-09-01 00:00:00');
        $this->day($mine, '2026-10-10', 0, 0);
        $this->entityManager->flush();

        $stored = $this->storedDays()->since([$mine->requireId()], '2026-10-01');

        self::assertTrue($stored->has($mine->requireId(), '2026-10-10'));
    }

    public function testNoSubscriptionsReadsNothing(): void
    {
        self::assertFalse($this->storedDays()->since([], '2026-10-01')->has(1, '2026-10-10'));
    }

    public function testDeletingTheSubscriptionDeletesItsHistory(): void
    {
        $mine = $this->subscriptionOfNewUser('cascade@example.com', '2026-09-01 00:00:00');
        $this->day($mine, '2026-10-10', 3, 0);
        $this->entityManager->flush();
        $subscriptionId = $mine->requireId();

        $this->entityManager->getConnection()
            ->executeStatement('DELETE FROM subscription WHERE id = ?', [$subscriptionId]);

        $stored = $this->storedDays()->since([$subscriptionId], '2026-10-01');
        self::assertFalse($stored->has($subscriptionId, '2026-10-10'));
    }
}
