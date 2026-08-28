<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The admin's per-account limit controls: the trial window and the
 * subscription cap. Embedded into User rather than left as two of its own
 * scalar columns — PHPMD's field-count ceiling on User is a proxy for a real
 * seam: both values are written exclusively by App\Service\Admin\UserLimits
 * and belong to that one concern. An embeddable keeps them there without the
 * join or lifecycle a separate entity would add; the column names are
 * unprefixed so the table itself is unchanged.
 */
#[ORM\Embeddable]
class AccountLimits
{
    /**
     * When this account's trial period ends. Null means the account has no
     * trial and no expiry — the state of every account created before this
     * column. App\Security\TrialExpiryGuard blocks the account (and flips its
     * status to Suspended on the next request) once this is in the past; the
     * date is retained after expiry so the admin can see the suspension came
     * from the trial rather than from a manual action.
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
