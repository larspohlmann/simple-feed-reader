<?php

declare(strict_types=1);

namespace App\Tests\Service\Maintenance\MaintenanceSweep;

use App\Service\LinkCard\Model\LinkCardSweepReportModel;
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
        self::assertEquals(new LinkCardSweepReportModel(1, 1), $this->reportWithinSecondsOfTheDeadline(60));
    }

    public function testLeavesThePostQueuedWhenLessThanOneFetchIsLeft(): void
    {
        self::assertEquals(LinkCardSweepReportModel::none(), $this->reportWithinSecondsOfTheDeadline(7));
    }

    private function reportWithinSecondsOfTheDeadline(int $seconds): LinkCardSweepReportModel
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

        return $sweep->run($clock->now()->modify(\sprintf('+%d seconds', $seconds)));
    }
}
