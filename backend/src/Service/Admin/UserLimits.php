<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\User;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * The admin's per-account limit controls: start or clear a trial, and set or
 * clear the per-user subscription cap. Starting a trial for, or clearing the
 * trial of, a trial-suspended account also restores its access — a silent
 * reinstatement, mirroring the suspended-restoration rule in
 * AdminUserController::approve().
 */
final readonly class UserLimits
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function startTrial(User $user, int $days): void
    {
        $user->setTrialEndsAt($this->clock->now()->modify(\sprintf('+%d days', $days)));
        $this->reactivateIfNotActive($user);
        $this->entityManager->flush();
    }

    public function clearTrial(User $user): void
    {
        if ($this->isTrialExpired($user)) {
            $this->reactivateIfNotActive($user);
        }

        $user->setTrialEndsAt(null);
        $this->entityManager->flush();
    }

    public function setSubscriptionLimit(User $user, ?int $maxSubscriptions): void
    {
        $user->setMaxSubscriptions($maxSubscriptions);
        $this->entityManager->flush();
    }

    private function isTrialExpired(User $user): bool
    {
        $trialEndsAt = $user->getTrialEndsAt();

        return null !== $trialEndsAt && $trialEndsAt <= $this->clock->now();
    }

    private function reactivateIfNotActive(User $user): void
    {
        if (UserStatus::Active === $user->getStatus()) {
            return;
        }

        $user->setStatus(UserStatus::Active);
        $user->setApprovedAt($this->clock->now());
    }
}
