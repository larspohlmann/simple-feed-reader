<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\SubscriptionRepository;
use App\Tests\DbTestCase;

final class UnreadCountsTest extends DbTestCase
{
    private function repo(): SubscriptionRepository
    {
        $repo = $this->em->getRepository(Subscription::class);
        self::assertInstanceOf(SubscriptionRepository::class, $repo);

        return $repo;
    }

    public function testCountsUnreadPerSubscriptionRespectingWatermarkAndState(): void
    {
        $user = new User('u@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($user);
        $feed = new Feed('https://example.com/f.xml');
        $this->em->persist($feed);
        $sub = new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $sub->setMarkedReadUntil(new \DateTimeImmutable('2026-07-10T00:00:00Z'));
        $this->em->persist($sub);

        // under watermark → read; above → unread; explicit read; explicit unread.
        foreach ([['a', '2026-07-05'], ['b', '2026-07-20'], ['c', '2026-07-21'], ['d', '2026-07-22']] as [$g, $d]) {
            $e = new Entry($feed, $g, null, $g, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
            $e->setPublishedAt(new \DateTimeImmutable($d . 'T00:00:00Z'));
            $this->em->persist($e);
            if ($g === 'c') {
                $st = new EntryState($user, $e);
                $st->setIsRead(true);
                $this->em->persist($st);
            }
        }
        $this->em->flush();

        // Unread: b and d (a is under watermark, c is explicitly read).
        $counts = $this->repo()->unreadCountsForUser((int) $user->getId());
        self::assertSame(2, $counts[(int) $sub->getId()] ?? 0);
    }

    public function testSubscriptionWithNoUnreadIsAbsentFromMap(): void
    {
        $user = new User('empty@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($user);
        $feed = new Feed('https://example.com/empty.xml');
        $this->em->persist($feed);
        $sub = new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($sub);
        $this->em->flush();

        $counts = $this->repo()->unreadCountsForUser((int) $user->getId());
        self::assertArrayNotHasKey((int) $sub->getId(), $counts);
    }
}
