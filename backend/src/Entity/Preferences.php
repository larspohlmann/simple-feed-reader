<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One row of per-account settings. Created by the User constructor rather than
 * by each caller, so no account-creation path can forget it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'user_preferences')]
#[ORM\UniqueConstraint(name: 'uniq_preferences_user', columns: ['user_id'])]
final class Preferences
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'preferences')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Naive UTC. Null until the account answers the one-time passkey enrolment offer, which then never shows again. */
    #[ORM\Column(name: 'passkey_offer_answered_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $passkeyOfferAnsweredAt = null;

    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getPasskeyOfferAnsweredAt(): ?\DateTimeImmutable
    {
        return $this->passkeyOfferAnsweredAt;
    }

    /**
     * Idempotent by the caller's contract (see PasskeyOffer::markAnswered),
     * not here: Preferences holds state, it does not decide whether a write
     * is allowed to happen.
     */
    public function markPasskeyOfferAnswered(\DateTimeImmutable $at): void
    {
        $this->passkeyOfferAnsweredAt = $at;
    }
}
