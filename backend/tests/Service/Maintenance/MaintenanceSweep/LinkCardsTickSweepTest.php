<?php

declare(strict_types=1);

namespace App\Tests\Service\Maintenance\MaintenanceSweep;

use App\Entity\Feed;
use App\Service\Maintenance\MaintenanceSweep\LinkCardsTickSweep;
use App\Tests\DbTestCase;
use App\Tests\Support\LinkCardSweepWiring;
use App\Tests\Support\QueuedLinkPosts;
use App\Tests\Support\ShareLinkCardsWiring;
use Symfony\Component\Clock\MockClock;

final class LinkCardsTickSweepTest extends DbTestCase
{
    public function testCardsTheQueuedPostWhenOneFetchFitsBeforeTheDeadline(): void
    {
        self::assertSame(['considered' => 1, 'completed' => 1], $this->outcomeWithinSecondsOfTheDeadline(60));
    }

    public function testLeavesThePostQueuedWhenLessThanOneFetchIsLeft(): void
    {
        self::assertSame(['considered' => 0, 'completed' => 0], $this->outcomeWithinSecondsOfTheDeadline(7));
    }

    /** @return array<string, int|bool> */
    private function outcomeWithinSecondsOfTheDeadline(int $seconds): array
    {
        $clock = new MockClock('2026-10-11 12:00:00', 'UTC');
        $feed = new Feed('https://one.example.com/feed');
        $this->entityManager->persist($feed);
        QueuedLinkPosts::persist(
            $this->entityManager,
            $feed,
            'link-post',
            '<p>https://example.com/1</p>',
            $clock->now()->modify('-1 minute'),
        );
        $this->entityManager->flush();
        $sweep = new LinkCardsTickSweep(
            LinkCardSweepWiring::build($this->entityManager, ShareLinkCardsWiring::titledPages(), $clock),
            $clock,
        );

        return $sweep->run($clock->now()->modify(\sprintf('+%d seconds', $seconds)))->outcome;
    }
}
