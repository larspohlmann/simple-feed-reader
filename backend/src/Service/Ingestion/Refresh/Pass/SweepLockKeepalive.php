<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Refresh\Pass;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Lock\Exception\ExceptionInterface as LockExceptionInterface;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\NoLock;

/**
 * Renews one sweep's lock on beat(), at most every RENEWAL_INTERVAL_SECONDS and never past the pass's ceiling.
 * Past the ceiling the lock may have expired and passed to the next sweep, so it no longer counts as held.
 */
final class SweepLockKeepalive
{
    public const int RENEWAL_INTERVAL_SECONDS = 20;

    public const int MAX_OVERRUN_SECONDS = 120;

    public const string LOCK_TAKEN_MESSAGE = 'Another process took the feed refresh lock';

    public const string RENEWAL_FAILED_MESSAGE = 'Could not renew the feed refresh lock';

    public const string CEILING_REACHED_MESSAGE = 'The feed refresh lock stopped renewing at its ceiling';

    private bool $holdingTheLock = true;

    private function __construct(
        private readonly LockInterface $lock,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly int $ceiling,
        private int $lastRenewalAttemptAt,
    ) {
    }

    public static function holding(
        LockInterface $lock,
        ClockInterface $clock,
        int $deadline,
        LoggerInterface $logger,
    ): self {
        return new self($lock, $clock, $logger, $deadline + self::MAX_OVERRUN_SECONDS, $clock->now()->getTimestamp());
    }

    public static function withoutLock(): self
    {
        $keepalive = self::holding(new NoLock(), new NativeClock(), \PHP_INT_MIN, new NullLogger());
        $keepalive->holdingTheLock = false;

        return $keepalive;
    }

    public function isHoldingTheLock(): bool
    {
        return $this->holdingTheLock;
    }

    public function beat(): void
    {
        if (!$this->holdingTheLock) {
            return;
        }

        $now = $this->clock->now()->getTimestamp();
        if ($now > $this->ceiling) {
            $this->holdingTheLock = false;
            $this->logger->warning(self::CEILING_REACHED_MESSAGE);

            return;
        }

        if ($now - $this->lastRenewalAttemptAt < self::RENEWAL_INTERVAL_SECONDS) {
            return;
        }

        $this->lastRenewalAttemptAt = $now;
        $this->renew();
    }

    private function renew(): void
    {
        try {
            $this->lock->refresh();
        } catch (LockConflictedException $conflict) {
            $this->holdingTheLock = false;
            $this->logger->warning(self::LOCK_TAKEN_MESSAGE, ['exception' => $conflict]);
        } catch (LockExceptionInterface $failure) {
            $this->logger->warning(self::RENEWAL_FAILED_MESSAGE, ['exception' => $failure]);
        }
    }
}
