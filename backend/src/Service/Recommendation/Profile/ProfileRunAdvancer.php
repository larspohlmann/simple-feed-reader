<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile;

use App\Entity\User;
use App\Repository\ProfileRunRepository;
use App\Service\Recommendation\Run\Model\TickDriver;
use App\Service\Recommendation\Run\TickLockTtl;
use App\Service\Recommendation\Run\UserTickLock;

/** The profile drivers' tick: the account's active profile run, under the lock its recommendation runs take. */
final readonly class ProfileRunAdvancer
{
    public function __construct(
        private UserTickLock $tickLock,
        private TickLockTtl $lockTtl,
        private ProfileConnections $profileConnections,
        private ProfileRunRepository $profileRuns,
        private ProfileRunTick $ticks,
    ) {
    }

    /** False when the account has no active profile run, or another tick holds the lock. */
    public function advance(User $user, TickDriver $driver): bool
    {
        $held = $this->tickLock->acquire(
            $user,
            $this->lockTtl->secondsForConnection($this->profileConnections->usableFor($user)),
        );
        if (null === $held) {
            return false;
        }

        try {
            return $this->tickTheActiveRun($user, $driver);
        } finally {
            $held->release();
        }
    }

    private function tickTheActiveRun(User $user, TickDriver $driver): bool
    {
        $profileRun = $this->profileRuns->findActiveForUser($user);
        if (null === $profileRun) {
            return false;
        }

        $this->ticks->advance($profileRun, $driver);

        return true;
    }
}
