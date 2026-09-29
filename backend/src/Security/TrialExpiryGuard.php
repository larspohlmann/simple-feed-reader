<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Enforces the trial lazily, as the app has no scheduler: the first request after it ends flips the account to
 * Suspended. trialEndsAt stays set, which is how admin screens tell a trial expiry from a manual suspend.
 */
final readonly class TrialExpiryGuard
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function enforce(User $user): void
    {
        $trialEndsAt = $user->getTrialEndsAt();

        if (null === $trialEndsAt || $trialEndsAt > $this->clock->now()) {
            return;
        }

        if (UserStatus::Active === $user->getStatus()) {
            $user->suspend();
            $this->entityManager->flush();
        }

        // Suspended, never Pending or Rejected: startTrial() activates the account and no firewall accepts a
        // non-Active token, so a trial-bearing account here was Active (above) or is already Suspended.
        throw new AccountStatusException(UserStatus::Suspended->value);
    }
}
