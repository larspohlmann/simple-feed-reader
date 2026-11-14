<?php

declare(strict_types=1);

namespace App\Tests\Service\Operations\Maintenance\MaintenanceSweep;

use App\Service\Social\Social\Model\HiddenProfileSwitchReportModel;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\Operations\Maintenance\MaintenanceSweep\HiddenProfilesTickSweep;
use App\Service\Social\Social\HiddenProfileSwitchSweep;
use App\Service\Social\Social\Model\HiddenProfileModel;
use App\Tests\DbTestCase;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StubHiddenProfiles;
use Symfony\Component\Clock\MockClock;

final class HiddenProfilesTickSweepTest extends DbTestCase
{
    public function testMovesTheHiddenProfileWhenOneCallFitsBeforeTheDeadline(): void
    {
        self::assertSame(1, $this->reportWithinSecondsOfTheDeadline(20)->started);
    }

    public function testLeavesTheHiddenProfileForLaterWhenLessThanOneCallIsLeft(): void
    {
        self::assertTrue($this->reportWithinSecondsOfTheDeadline(19)->isIdle());
    }

    private function reportWithinSecondsOfTheDeadline(int $seconds): HiddenProfileSwitchReportModel
    {
        $clock = new MockClock('2026-10-11 12:00:00', 'UTC');
        $now = $clock->now();
        $subscriber = new User('tick-sweep-hidden-profiles@example.com', $now);
        $this->entityManager->persist($subscriber);
        $this->entityManager->flush();
        $this->entityManager->persist(SocialAccounts::blueskyOf($subscriber));
        $refused = new Feed('https://bsky.app/profile/did:plc:rusty/rss');
        $refused->recordFailedFetch($now, 'https://bsky.app/profile/did:plc:rusty/rss: HTTP 403', 60);
        $refused->scheduleNextFetchAt($now->modify('+1 hour'));
        $this->entityManager->persist($refused);
        $this->entityManager->persist(new Subscription($subscriber, $refused, $now));
        $this->entityManager->flush();
        $hiddenProfiles = self::getContainer()->get(StubHiddenProfiles::class);
        self::assertInstanceOf(StubHiddenProfiles::class, $hiddenProfiles);
        $hiddenProfiles->hide(new HiddenProfileModel('did:plc:rusty', 'Rusty'));
        $switches = self::getContainer()->get(HiddenProfileSwitchSweep::class);
        self::assertInstanceOf(HiddenProfileSwitchSweep::class, $switches);

        return new HiddenProfilesTickSweep($switches, $clock)
            ->run($now->modify(\sprintf('+%d seconds', $seconds)));
    }
}
