<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile;

use App\Entity\ProfileRun;
use App\Service\Recommendation\Profile\Pass\ProfileTick;
use App\Service\Recommendation\Run\ProviderCallHeartbeat\TickLockKeepalive;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/** How a profile run ends badly; the stored profile is never touched here. */
final readonly class ProfileRunFailure
{
    public const string PROVIDER_FAILED = 'The AI provider at %s failed: %s';

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

    /** One strike; the run fails at MAX_TRANSPORT_FAILURES. A tick that lost its lock records nothing. */
    public function recordTransportFailure(ProfileTick $tick, string $failureDetail): void
    {
        $profileRun = $tick->profileRun;
        if ($this->keepalive->hasLostTheLock()) {
            $this->entityManager->refresh($profileRun);

            return;
        }

        $profileRun->recordTransportFailure();
        if ($profileRun->hasExhaustedTransportRetries()) {
            $profileRun->fail(
                \sprintf(self::PROVIDER_FAILED, $tick->connection->getBaseUrl(), $failureDetail),
                $this->clock->now(),
            );
        }
        $this->entityManager->flush();
    }
}
