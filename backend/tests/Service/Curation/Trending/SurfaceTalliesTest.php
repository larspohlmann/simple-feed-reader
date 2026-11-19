<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\TrendingWindow;
use App\Service\Curation\Trending\SurfaceTallies;
use App\Service\Curation\Trending\TrendingStories;
use App\Service\Library\Subscription\SubscriptionTallyReader;
use App\Tests\DbTestCase;

final class SurfaceTalliesTest extends DbTestCase
{
    public function testTalliesCarryTheSubscriptionCountsAndTheUnopenedStories(): void
    {
        $when = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $user = new User('surface-tallies@example.com', $when);
        $this->entityManager->persist($user);
        $feed = new Feed('https://example.com/surface-tallies.xml');
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($user, $feed, $when));
        $this->entityManager->persist(new Entry($feed, 'unread', null, 'unread', $when, $when));
        $this->entityManager->flush();

        $surface = self::getContainer()->get(SurfaceTallies::class);
        self::assertInstanceOf(SurfaceTallies::class, $surface);
        $reader = self::getContainer()->get(SubscriptionTallyReader::class);
        self::assertInstanceOf(SubscriptionTallyReader::class, $reader);
        $stories = self::getContainer()->get(TrendingStories::class);
        self::assertInstanceOf(TrendingStories::class, $stories);

        $tallies = $surface->buildTallies($user, TrendingWindow::Day);

        self::assertSame(1, $tallies->subscriptions->unreadTotals->allItems);
        self::assertEquals($reader->countTallies($user), $tallies->subscriptions);
        self::assertEquals($stories->unopenedFor($user, TrendingWindow::Day), $tallies->trending);
    }
}
