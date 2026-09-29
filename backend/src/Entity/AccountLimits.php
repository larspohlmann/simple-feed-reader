<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The admin's per-account limits, the trial window and the subscription cap, written only by
 * App\Service\Admin\UserLimits.
 */
#[ORM\Embeddable]
final class AccountLimits
{
    /**
     * Null means no trial and no expiry. TrialExpiryGuard suspends the account once this has passed, and the date
     * stays so the admin can tell the suspension came from the trial.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $trialEndsAt = null;

    /**
     * A per-user override of the global subscription cap. Null means "fall back
     * to SubscriptionService::MAX_SUBSCRIPTIONS_PER_USER" — resolved in exactly
     * one place, App\Service\Subscription\SubscriptionLimitResolver.
     */
    #[ORM\Column(nullable: true)]
    private ?int $maxSubscriptions = null;

    public function getTrialEndsAt(): ?\DateTimeImmutable
    {
        return $this->trialEndsAt;
    }

    public function setTrialEndsAt(?\DateTimeImmutable $trialEndsAt): void
    {
        $this->trialEndsAt = $trialEndsAt;
    }

    public function getMaxSubscriptions(): ?int
    {
        return $this->maxSubscriptions;
    }

    public function setMaxSubscriptions(?int $maxSubscriptions): void
    {
        $this->maxSubscriptions = $maxSubscriptions;
    }
}
