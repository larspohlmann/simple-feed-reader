<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile;

use App\Entity\ProfileRun;
use App\Service\Recommendation\Profile\Pass\ProfileTick;
use App\Service\Recommendation\Run\ProviderCallHeartbeat\TickLockKeepalive;
use App\Service\Recommendation\Run\RecommendationTransportFailureRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/** How a profile run ends badly; the stored profile is never touched here. */
final readonly class ProfileRunFailure
{
    public const string UNEXPECTED_FAILURE = 'An unexpected error stopped the profile run.';

    public function __construct(
        private TickLockKeepalive $keepalive,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function fail(ProfileRun $profileRun, string $message): void
    {
        $profileRun->fail($message, $this->clock->now());
        $this->entityManager->flush();
    }

    /** No strike and no retry: the same request would be rejected again. */
    public function failRejected(ProfileTick $tick, string $rejection): void
    {
        if ($this->refreshedAfterLosingTheLock($tick->profileRun)) {
            return;
        }

        $this->fail($tick->profileRun, $this->providerFailed($tick, $rejection));
    }

    /** One strike; the run fails at MAX_TRANSPORT_FAILURES. */
    public function recordTransportFailure(ProfileTick $tick, string $failureDetail): void
    {
        if ($this->refreshedAfterLosingTheLock($tick->profileRun)) {
            return;
        }

        $this->strike($tick->profileRun, $this->providerFailed($tick, $failureDetail));
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
    private function refreshedAfterLosingTheLock(ProfileRun $profileRun): bool
    {
        if (!$this->keepalive->hasLostTheLock()) {
            return false;
        }

        $this->entityManager->refresh($profileRun);

        return true;
    }

    private function providerFailed(ProfileTick $tick, string $failureDetail): string
    {
        return \sprintf(
            RecommendationTransportFailureRecorder::PROVIDER_FAILED,
            $tick->connection->getBaseUrl(),
            $failureDetail,
        );
    }
}
