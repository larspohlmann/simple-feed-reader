<?php

declare(strict_types=1);

namespace App\Tests\Service\Maintenance\MaintenanceSweep;

use App\Service\Search\Membership\Model\SavedSearchMembershipSweepReportModel;
use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SavedSearch;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\Maintenance\MaintenanceSweep\SavedSearchMembershipsTickSweep;
use App\Tests\DbTestCase;
use App\Tests\Support\MembershipSweepFactory;
use App\Tests\Support\RecordingSavedSearchMatcher;
use Symfony\Component\Clock\MockClock;

final class SavedSearchMembershipsTickSweepTest extends DbTestCase
{
    public function testSweepsTheMembershipsWithinTheDeadline(): void
    {
        $clock = $this->seedOneSearchAndEntry();

        $report = $this->sweep($clock)->run($clock->now()->modify('+1 minute'));

        self::assertTrue($report->caughtUp);
        self::assertGreaterThanOrEqual(1, $report->entriesScanned);
    }

    public function testScansNothingOnceTheDeadlineHasPassed(): void
    {
        $clock = $this->seedOneSearchAndEntry();

        $report = $this->sweep($clock)->run($clock->now()->modify('-1 second'));

        self::assertSame(0, $report->entriesScanned);
        self::assertFalse($report->caughtUp);
    }

    public function testAnIdleTickReportsNoSweep(): void
    {
        self::assertEquals(
            SavedSearchMembershipSweepReportModel::none(),
            $this->sweep(new MockClock())->idleReport(),
        );
    }

    private function seedOneSearchAndEntry(): MockClock
    {
        $clock = new MockClock('2031-05-14 12:00:00', 'UTC');
        $owner = new User('tick-sweep-memberships@example.com', $clock->now());
        $owner->approve($clock->now());
        $feed = new Feed('https://memberships.example.com/feed');
        $this->entityManager->persist($owner);
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($owner, $feed, $clock->now()));
        $this->entityManager->persist(new SavedSearch($owner, 'climate', false));
        $this->entityManager->persist(new Entry(
            $feed,
            'membership-1',
            'https://memberships.example.com/1',
            'Climate',
            $clock->now()->modify('-1 day'),
            $clock->now()->modify('-1 day'),
        ));
        $this->entityManager->flush();

        return $clock;
    }

    private function sweep(MockClock $clock): SavedSearchMembershipsTickSweep
    {
        return new SavedSearchMembershipsTickSweep(
            MembershipSweepFactory::fromContainer(
                self::getContainer(),
                $this->entityManager,
                new RecordingSavedSearchMatcher(),
                $clock,
            ),
            $clock,
        );
    }
}
