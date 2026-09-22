<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Repository\EntryMembershipSweepRepository;
use App\Repository\SavedSearchEntryMembershipRepository;
use App\Repository\SavedSearchRepository;
use App\Service\Search\Membership\SavedSearchMembershipSweep;
use App\Service\Worker\Handler\SweepSavedSearchMembershipsHandler;
use App\Service\Worker\Message\SweepSavedSearchMemberships;
use App\Tests\DbTestCase;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\RecordingSavedSearchMatcher;
use Symfony\Component\Clock\MockClock;

final class SweepSavedSearchMembershipsHandlerTest extends DbTestCase
{
    public function testFiringRunsTheSweepAndLogsItsReport(): void
    {
        $searches = self::getContainer()->get(SavedSearchRepository::class);
        self::assertInstanceOf(SavedSearchRepository::class, $searches);
        $entries = self::getContainer()->get(EntryMembershipSweepRepository::class);
        self::assertInstanceOf(EntryMembershipSweepRepository::class, $entries);
        $memberships = self::getContainer()->get(SavedSearchEntryMembershipRepository::class);
        self::assertInstanceOf(SavedSearchEntryMembershipRepository::class, $memberships);
        $logger = new RecordingLogger();
        $sweep = new SavedSearchMembershipSweep(
            $searches,
            $entries,
            $memberships,
            new RecordingSavedSearchMatcher(),
            $this->em,
            new MockClock('2026-09-22T10:00:00'),
            $logger,
        );

        (new SweepSavedSearchMembershipsHandler($sweep, $logger))->__invoke(new SweepSavedSearchMemberships());

        self::assertCount(1, $logger->records);
        self::assertSame('info', $logger->records[0]['level']);
        $report = $logger->records[0]['context']['report'];
        self::assertIsArray($report);
        self::assertTrue($report['caughtUp']);
    }
}
