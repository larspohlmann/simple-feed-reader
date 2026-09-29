<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\User;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/** Starting a trial, or clearing an expired one, reactivates the account without a mail: it is a reinstatement. */
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

        $user->approve($this->clock->now());
    }
}
