<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Service\Worker\Handler\SweepSavedSearchMembershipsHandler;
use App\Service\Worker\Message\SweepSavedSearchMemberships;
use App\Tests\DbTestCase;
use App\Tests\Support\MembershipSweepFactory;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\RecordingSavedSearchMatcher;
use Symfony\Component\Clock\MockClock;

final class SweepSavedSearchMembershipsHandlerTest extends DbTestCase
{
    public function testFiringRunsTheSweepAndLogsItsReport(): void
    {
        $logger = new RecordingLogger();
        $sweep = MembershipSweepFactory::fromContainer(
            self::getContainer(),
            $this->em,
            new RecordingSavedSearchMatcher(),
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
