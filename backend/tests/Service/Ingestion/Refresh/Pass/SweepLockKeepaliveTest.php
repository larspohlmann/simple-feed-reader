<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingestion\Refresh\Pass;

use App\Service\Ingestion\Refresh\Pass\SweepLockKeepalive;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\RefreshCountingLock;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\Exception\LockAcquiringException;
use Symfony\Component\Lock\Exception\LockConflictedException;

final class SweepLockKeepaliveTest extends TestCase
{
    private MockClock $clock;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-23 10:00:00');
        $this->logger = new RecordingLogger();
    }

    public function testABeatBeforeTheIntervalRenewsNothing(): void
    {
        $lock = new RefreshCountingLock();
        $keepalive = $this->buildKeepalive($lock, 120);

        $this->clock->sleep(10);
        $keepalive->beat();

        self::assertSame(0, $lock->refreshCount());
    }

    public function testABeatAfterTheIntervalRenewsTheLock(): void
    {
        $lock = new RefreshCountingLock();
        $keepalive = $this->buildKeepalive($lock, 120);

        $this->clock->sleep(25);
        $keepalive->beat();

        self::assertSame(1, $lock->refreshCount());
    }

    public function testBeatsInABurstRenewOncePerInterval(): void
    {
        $lock = new RefreshCountingLock();
        $keepalive = $this->buildKeepalive($lock, 120);

        $this->clock->sleep(25);
        $keepalive->beat();
        $this->clock->sleep(5);
        $keepalive->beat();

        self::assertSame(1, $lock->refreshCount());

        $this->clock->sleep(20);
        $keepalive->beat();

        self::assertSame(2, $lock->refreshCount());
    }

    public function testRenewalStopsAtTheCeiling(): void
    {
        $lock = new RefreshCountingLock();
        $keepalive = $this->buildKeepalive($lock, 10);

        for ($beat = 1; $beat <= 6; ++$beat) {
            $this->clock->sleep(20);
            $keepalive->beat();
        }

        $renewalsUpToTheCeiling = $lock->refreshCount();

        foreach ([20, 20, 340] as $seconds) {
            $this->clock->sleep($seconds);
            $keepalive->beat();
        }

        self::assertSame([6, 6], [$renewalsUpToTheCeiling, $lock->refreshCount()]);
        self::assertSame(1, $this->countLogged(SweepLockKeepalive::CEILING_REACHED_MESSAGE));
    }

    public function testASweepPastItsCeilingNoLongerHoldsTheLock(): void
    {
        $keepalive = $this->buildKeepalive(new RefreshCountingLock(), 10);

        $this->clock->sleep(10 + SweepLockKeepalive::MAX_OVERRUN_SECONDS);
        $keepalive->beat();
        $heldAtTheCeiling = $keepalive->isHoldingTheLock();
        $this->clock->sleep(1);
        $keepalive->beat();

        self::assertSame([true, false], [$heldAtTheCeiling, $keepalive->isHoldingTheLock()]);
    }

    public function testABeatAtTheCeilingRenewsAndOneSecondLaterDoesNot(): void
    {
        $secondsToDeadline = 20 - SweepLockKeepalive::MAX_OVERRUN_SECONDS;
        $lockBeatenAtTheCeiling = new RefreshCountingLock();
        $beatenAtTheCeiling = $this->buildKeepalive($lockBeatenAtTheCeiling, $secondsToDeadline);
        $lockBeatenPastTheCeiling = new RefreshCountingLock();
        $beatenPastTheCeiling = $this->buildKeepalive($lockBeatenPastTheCeiling, $secondsToDeadline);

        $this->clock->sleep(20);
        $beatenAtTheCeiling->beat();
        $this->clock->sleep(1);
        $beatenPastTheCeiling->beat();

        self::assertSame(
            [1, 0],
            [$lockBeatenAtTheCeiling->refreshCount(), $lockBeatenPastTheCeiling->refreshCount()],
        );
        self::assertSame(1, $this->countLogged(SweepLockKeepalive::CEILING_REACHED_MESSAGE));
    }

    public function testALockTakenByAnotherProcessIsRecorded(): void
    {
        $lock = new RefreshCountingLock();
        $lock->conflictOnNextRefresh();
        $keepalive = $this->buildKeepalive($lock, 120);

        $this->clock->sleep(25);
        $keepalive->beat();

        self::assertFalse($keepalive->isHoldingTheLock());
        self::assertSame(1, $this->countLogged(SweepLockKeepalive::LOCK_TAKEN_MESSAGE));
        self::assertInstanceOf(LockConflictedException::class, $this->loggedException());
    }

    public function testALockTakenByAnotherProcessIsNeverRenewedAgain(): void
    {
        $lock = new RefreshCountingLock();
        $lock->conflictOnNextRefresh();
        $keepalive = $this->buildKeepalive($lock, 120);

        for ($beat = 1; $beat <= 3; ++$beat) {
            $this->clock->sleep(25);
            $keepalive->beat();
        }

        self::assertSame(1, $lock->refreshCount());
        self::assertSame(1, $this->countLogged(SweepLockKeepalive::LOCK_TAKEN_MESSAGE));
    }

    public function testAStoreFailureIsLoggedAndTheLockIsNotCountedLost(): void
    {
        $lock = new RefreshCountingLock();
        $lock->throwOnNextRefresh();
        $keepalive = $this->buildKeepalive($lock, 120);

        $this->clock->sleep(25);
        $keepalive->beat();

        self::assertTrue($keepalive->isHoldingTheLock());
        self::assertSame(1, $this->countLogged(SweepLockKeepalive::RENEWAL_FAILED_MESSAGE));
        self::assertInstanceOf(LockAcquiringException::class, $this->loggedException());
    }

    public function testWithoutALockNoLockIsHeld(): void
    {
        self::assertFalse(SweepLockKeepalive::withoutLock()->isHoldingTheLock());
    }

    public function testALiveSweepHoldsItsLock(): void
    {
        $keepalive = $this->buildKeepalive(new RefreshCountingLock(), 120);

        $this->clock->sleep(25);
        $keepalive->beat();

        self::assertTrue($keepalive->isHoldingTheLock());
    }

    private function buildKeepalive(RefreshCountingLock $lock, int $secondsToDeadline): SweepLockKeepalive
    {
        $deadline = $this->clock->now()->getTimestamp() + $secondsToDeadline;

        return SweepLockKeepalive::holding($lock, $this->clock, $deadline, $this->logger);
    }

    private function loggedException(): mixed
    {
        return $this->logger->records[0]['context']['exception'] ?? null;
    }

    private function countLogged(string $message): int
    {
        return \count(array_filter(
            $this->logger->records,
            static fn (array $record): bool => $record['message'] === $message,
        ));
    }
}
