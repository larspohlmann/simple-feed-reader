<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Refresh\Pass;

use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\Exception\ExceptionInterface as LockExceptionInterface;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\LockInterface;

/** Renews one sweep's lock on beat(), at most every RENEWAL_INTERVAL_SECONDS and never past the pass's ceiling. */
final class SweepLockKeepalive
{
    public const int RENEWAL_INTERVAL_SECONDS = 20;

    public const int MAX_OVERRUN_SECONDS = 120;

    public const string LOCK_TAKEN_MESSAGE = 'Another process took the feed refresh lock';

    public const string RENEWAL_FAILED_MESSAGE = 'Could not renew the feed refresh lock';

    public const string CEILING_REACHED_MESSAGE = 'The feed refresh lock stopped renewing at its ceiling';

    private bool $lockLost = false;

    private bool $renewalStopped = false;

    private function __construct(
        private readonly ?LockInterface $lock,
        private readonly ?ClockInterface $clock,
        private readonly ?LoggerInterface $logger,
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
        return new self(null, null, null, 0, 0);
    }

    public function hasLostTheLock(): bool
    {
        return $this->lockLost;
    }

    public function beat(): void
    {
        if (null === $this->lock || null === $this->clock || $this->renewalStopped) {
            return;
        }

        $now = $this->clock->now()->getTimestamp();
        if ($now > $this->ceiling) {
            $this->renewalStopped = true;
            $this->logger?->warning(self::CEILING_REACHED_MESSAGE);

            return;
        }

        if ($now - $this->lastRenewalAttemptAt < self::RENEWAL_INTERVAL_SECONDS) {
            return;
        }

        $this->lastRenewalAttemptAt = $now;
        $this->renew($this->lock);
    }

    private function renew(LockInterface $lock): void
    {
        try {
            $lock->refresh();
        } catch (LockConflictedException $conflict) {
            $this->lockLost = true;
            $this->logger?->warning(self::LOCK_TAKEN_MESSAGE, ['exception' => $conflict]);
        } catch (LockExceptionInterface $failure) {
            $this->logger?->warning(self::RENEWAL_FAILED_MESSAGE, ['exception' => $failure]);
        }
    }
}
