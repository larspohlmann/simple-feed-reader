<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingestion\Refresh\Pass;

use App\Service\Ingestion\Refresh\Pass\SweepLockKeepalive;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\RenewalCountingLock;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Exception\LockStorageException;

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
        $lock = new RenewalCountingLock();
        $keepalive = $this->buildKeepalive($lock, 120);

        $this->clock->sleep(10);
        $keepalive->beat();

        self::assertSame(0, $lock->renewals());
    }

    public function testABeatAfterTheIntervalRenewsTheLock(): void
    {
        $lock = new RenewalCountingLock();
        $keepalive = $this->buildKeepalive($lock, 120);

        $this->clock->sleep(25);
        $keepalive->beat();

        self::assertSame(1, $lock->renewals());
    }

    public function testBeatsInABurstRenewOncePerInterval(): void
    {
        $lock = new RenewalCountingLock();
        $keepalive = $this->buildKeepalive($lock, 120);

        $this->clock->sleep(25);
        $keepalive->beat();
        $this->clock->sleep(5);
        $keepalive->beat();

        self::assertSame(1, $lock->renewals());

        $this->clock->sleep(20);
        $keepalive->beat();

        self::assertSame(2, $lock->renewals());
    }

    public function testRenewalStopsAtTheCeiling(): void
    {
        $lock = new RenewalCountingLock();
        $keepalive = $this->buildKeepalive($lock, 10);

        for ($beat = 1; $beat <= 6; ++$beat) {
            $this->clock->sleep(20);
            $keepalive->beat();
        }

        $renewalsUpToTheCeiling = $lock->renewals();

        foreach ([20, 20, 340] as $seconds) {
            $this->clock->sleep($seconds);
            $keepalive->beat();
        }

        self::assertSame([6, 6], [$renewalsUpToTheCeiling, $lock->renewals()]);
        self::assertSame(1, $this->countLogged(SweepLockKeepalive::CEILING_REACHED_MESSAGE));
    }

    public function testALockTakenByAnotherProcessIsRecorded(): void
    {
        $keepalive = $this->buildKeepalive(new RenewalCountingLock(null, new LockConflictedException()), 120);

        $this->clock->sleep(25);
        $keepalive->beat();

        self::assertTrue($keepalive->hasLostTheLock());
        self::assertSame(1, $this->countLogged(SweepLockKeepalive::LOCK_TAKEN_MESSAGE));
    }

    public function testAStoreFailureIsLoggedAndTheLockIsNotCountedLost(): void
    {
        $keepalive = $this->buildKeepalive(new RenewalCountingLock(null, new LockStorageException()), 120);

        $this->clock->sleep(25);
        $keepalive->beat();

        self::assertFalse($keepalive->hasLostTheLock());
        self::assertSame(1, $this->countLogged(SweepLockKeepalive::RENEWAL_FAILED_MESSAGE));
    }

    public function testWithoutALockEveryBeatIsANoOp(): void
    {
        $keepalive = SweepLockKeepalive::withoutLock();

        $this->clock->sleep(25);
        $keepalive->beat();
        $this->clock->sleep(475);
        $keepalive->beat();

        self::assertFalse($keepalive->hasLostTheLock());
    }

    private function buildKeepalive(RenewalCountingLock $lock, int $secondsToDeadline): SweepLockKeepalive
    {
        $deadline = $this->clock->now()->getTimestamp() + $secondsToDeadline;

        return SweepLockKeepalive::holding($lock, $this->clock, $deadline, $this->logger);
    }

    private function countLogged(string $message): int
    {
        return \count(array_filter(
            $this->logger->records,
            static fn (array $record): bool => $record['message'] === $message,
        ));
    }
}
