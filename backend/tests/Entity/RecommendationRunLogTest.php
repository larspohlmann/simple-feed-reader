<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ProfileRun;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Entity\User;
use App\Enum\CallPhase;
use App\Enum\ProfileRunTrigger;
use PHPUnit\Framework\TestCase;

final class RecommendationRunLogTest extends TestCase
{
    public function testARunRowNamesItsRunAndNoProfileRun(): void
    {
        $run = new RecommendationRun($this->user(), new \DateTimeImmutable('2026-10-03 09:00:00'));

        $log = RecommendationRunLog::forRun($run, CallPhase::Batch, 4, 2, '{"model":"m"}', $this->sentAt());

        self::assertSame($run, $log->getRun());
        self::assertNull($log->getProfileRun());
        self::assertSame(CallPhase::Batch, $log->getPhase());
        self::assertSame(4, $log->getBatchNumber());
        self::assertSame(2, $log->getAttempt());
    }

    public function testAProfileRunRowIsADistillCallWithoutABatchOrARun(): void
    {
        $profileRun = new ProfileRun($this->user(), ProfileRunTrigger::Manual, $this->sentAt());

        $log = RecommendationRunLog::forProfileRun($profileRun, 3, '{"model":"p"}', $this->sentAt());

        self::assertSame($profileRun, $log->getProfileRun());
        self::assertNull($log->getRun());
        self::assertSame(CallPhase::Distill, $log->getPhase());
        self::assertNull($log->getBatchNumber());
        self::assertSame(3, $log->getAttempt());
        self::assertSame('{"model":"p"}', $log->getRequestBody());
    }

    private function user(): User
    {
        return new User('run-log@example.test', new \DateTimeImmutable('2026-10-01 08:00:00'));
    }

    private function sentAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-03 09:01:00');
    }
}
