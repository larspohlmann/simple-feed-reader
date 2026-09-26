<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\RunCallAttempts;
use PHPUnit\Framework\TestCase;

final class RunCallAttemptsTest extends TestCase
{
    public function testAFreshInstanceHasNoAttemptsNoFailuresAndNoReply(): void
    {
        $callAttempts = new RunCallAttempts();

        self::assertSame(0, $callAttempts->attempts());
        self::assertSame(0, $callAttempts->transportFailures());
        self::assertNull($callAttempts->lastInvalidReply());
    }

    public function testRecordInvalidReplyIncrementsAttemptsAndKeepsTheReply(): void
    {
        $callAttempts = new RunCallAttempts();

        $callAttempts->recordInvalidReply('garbage');

        self::assertSame(1, $callAttempts->attempts());
        self::assertSame('garbage', $callAttempts->lastInvalidReply());
    }

    public function testRecordTransportFailureCountsEachFailure(): void
    {
        $callAttempts = new RunCallAttempts();

        $callAttempts->recordTransportFailure();
        $callAttempts->recordTransportFailure();

        self::assertSame(2, $callAttempts->transportFailures());
    }

    public function testAttemptsAndTransportFailuresCountIndependently(): void
    {
        $callAttempts = new RunCallAttempts();

        $callAttempts->recordInvalidReply('garbage');
        $callAttempts->recordTransportFailure();

        self::assertSame(1, $callAttempts->attempts());
        self::assertSame(1, $callAttempts->transportFailures());
    }

    public function testResetClearsAttemptsFailuresAndTheLastReply(): void
    {
        $callAttempts = new RunCallAttempts();
        $callAttempts->recordInvalidReply('garbage');
        $callAttempts->recordTransportFailure();

        $callAttempts->reset();

        self::assertSame(0, $callAttempts->attempts());
        self::assertSame(0, $callAttempts->transportFailures());
        self::assertNull($callAttempts->lastInvalidReply());
    }

    public function testResetTransportFailuresLeavesAttemptsAndTheLastReply(): void
    {
        $callAttempts = new RunCallAttempts();
        $callAttempts->recordInvalidReply('garbage');
        $callAttempts->recordTransportFailure();

        $callAttempts->resetTransportFailures();

        self::assertSame(1, $callAttempts->attempts());
        self::assertSame(0, $callAttempts->transportFailures());
        self::assertSame('garbage', $callAttempts->lastInvalidReply());
    }
}
