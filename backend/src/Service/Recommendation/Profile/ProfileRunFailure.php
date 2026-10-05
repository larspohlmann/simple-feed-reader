<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile;

use App\Entity\ProfileRun;
use App\Service\Ai\Exception\ProviderRejectedRequestException;
use App\Service\Recommendation\Profile\Pass\ProfileTick;
use App\Service\Recommendation\Run\ProviderCallHeartbeat\TickLockKeepalive;
use App\Service\Recommendation\Run\RejectedRequestFallback\RejectedRequestFallbackInterface;
use App\Service\Recommendation\Run\Support\ProviderFailedMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/** How a profile run ends badly; the stored profile is never touched here. */
final readonly class ProfileRunFailure
{
    public const string UNEXPECTED_FAILURE = 'An unexpected error stopped the profile run.';

    public function __construct(
        private TickLockKeepalive $keepalive,
        private RejectedRequestFallbackInterface $fallback,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function fail(ProfileRun $profileRun, string $message): void
    {
        $profileRun->fail($message, $this->clock->now());
        $this->entityManager->flush();
    }

    public function failRejected(ProfileTick $tick, ProviderRejectedRequestException $rejection): void
    {
        if ($this->refreshIfTheLockWasLost($tick->profileRun)) {
            return;
        }

        if ($this->fallback->absorbs($tick->connection, $rejection)) {
            return;
        }

        $this->fail(
            $tick->profileRun,
            ProviderFailedMessage::of($tick->connection->getBaseUrl(), $rejection->getMessage()),
        );
    }

    /** One strike; the run fails at MAX_TRANSPORT_FAILURES. */
    public function recordTransportFailure(ProfileTick $tick, string $failureDetail): void
    {
        if ($this->refreshIfTheLockWasLost($tick->profileRun)) {
            return;
        }

        $this->strike(
            $tick->profileRun,
            ProviderFailedMessage::of($tick->connection->getBaseUrl(), $failureDetail),
        );
    }

    /**
     * A strike over the run as last saved, dropping what the failed tick half-did to it. A closed manager can write
     * nothing, so it records nothing and the tick's own failure is the one reported.
     */
    public function recordUnexpectedFailure(ProfileRun $profileRun): void
    {
        if (!$this->entityManager->isOpen()) {
            return;
        }

        $this->entityManager->refresh($profileRun);
        if ($this->keepalive->hasLostTheLock() || !$profileRun->getStatus()->isActive()) {
            return;
        }

        $this->strike($profileRun, self::UNEXPECTED_FAILURE);
    }

    private function strike(ProfileRun $profileRun, string $failureMessage): void
    {
        $profileRun->recordTransportFailure();
        if ($profileRun->hasExhaustedTransportRetries()) {
            $profileRun->fail($failureMessage, $this->clock->now());
        }
        $this->entityManager->flush();
    }

    /** A tick that lost its lock records nothing: the run is whatever the lock's new holder saved. */
    private function refreshIfTheLockWasLost(ProfileRun $profileRun): bool
    {
        if (!$this->keepalive->hasLostTheLock()) {
            return false;
        }

        $this->entityManager->refresh($profileRun);

        return true;
    }
}
