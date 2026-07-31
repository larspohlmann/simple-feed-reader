<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Enforces the trial period. There is no scheduler in this app by design, so
 * the trial → suspended transition is lazy: the first request an account makes
 * after its trial ends flips its stored status to Suspended and is refused.
 *
 * The flip is a deliberate, named side effect kept out of the security
 * checkers themselves, which only delegate here. It happens at most once per
 * account (afterwards the status is already Suspended), so a live trial costs
 * only a null check and a date comparison.
 *
 * The date is left in place after expiry: a Suspended account whose
 * trialEndsAt is in the past is how the admin screens tell a trial expiry apart
 * from a manual suspend.
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

        throw new AccountStatusException(UserStatus::Suspended->value);
    }
}
