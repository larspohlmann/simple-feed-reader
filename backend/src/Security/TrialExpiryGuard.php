<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Enforces the trial period. There's no scheduler in this app by design, so
 * the trial -> suspended transition is lazy: the first request after a
 * trial ends flips the stored status to Suspended and is refused.
 *
 * The flip is a deliberate, named side effect kept out of the security
 * checkers themselves, which only delegate here. It happens at most once per
 * account, so a live trial costs only a null check and a date comparison.
 *
 * The date stays in place after expiry: a Suspended account with a past
 * trialEndsAt is how admin screens tell a trial expiry apart from a manual
 * suspend.
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
            $user->setStatus(UserStatus::Suspended);
            $this->entityManager->flush();
        }

        // Always Suspended, never Pending/Rejected: startTrial() reactivates any
        // account to Active, and no firewall accepts a non-Active token, so a
        // trial-bearing account here is either Active (handled above) or
        // already Suspended.
        throw new AccountStatusException(UserStatus::Suspended->value);
    }
}
