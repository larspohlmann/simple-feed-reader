<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile;

use App\Entity\ProfileRun;
use App\Service\Recommendation\Run\Model\TickDriver;
use App\Service\Recommendation\Run\TickLockTtl;
use App\Service\Recommendation\Run\UserTickLock;
use Doctrine\ORM\EntityManagerInterface;

/** The profile drivers' tick: one profile run, under the lock its account's recommendation runs take. */
final readonly class ProfileRunAdvancer
{
    public function __construct(
        private UserTickLock $tickLock,
        private TickLockTtl $lockTtl,
        private ProfileConnections $profileConnections,
        private ProfileRunTick $ticks,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** Skips the turn when another tick holds the lock, or when the run ended before this one took it. */
    public function advance(ProfileRun $profileRun, TickDriver $driver): void
    {
        $user = $profileRun->getUser();
        $connection = $this->profileConnections->usableFor($user);
        $held = $this->tickLock->acquire($user, $this->lockTtl->secondsForConnection($connection));
        if (null === $held) {
            return;
        }

        try {
            $this->entityManager->refresh($profileRun);
            if ($profileRun->getStatus()->isActive()) {
                $this->ticks->advance($profileRun, $connection, $driver);
            }
        } finally {
            $held->release();
        }
    }
}
