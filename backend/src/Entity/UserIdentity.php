<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserIdentityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserIdentityRepository::class)]
#[ORM\Table(name: 'user_identity')]
#[ORM\UniqueConstraint(name: 'uniq_identity_provider_uid', columns: ['provider', 'provider_user_id'])]
class UserIdentity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /**
     * Deliberately left on the table's default collation, unlike
     * $providerUserId below.
     *
     * This column's values are a closed vocabulary written by our own code from
     * a hard-coded OAuthProviderInterface::getName(). They are never
     * provider-supplied and never user-supplied, so a case-insensitive
     * comparison here cannot be attacked — the only string that ever reaches it
     * is one of the literals we ship. Pinning a collation on a column whose
     * every value we choose would be tidiness dressed up as security, and would
     * blur the reason the column below genuinely needs one.
     */
    #[ORM\Column(length: 30)]
    private string $provider;

    /**
     * `_bin` collation, pinned explicitly.
     *
     * Without it MySQL inherits the table default (utf8mb4_0900_ai_ci) and
     * compares this column case-insensitively, while SQLite compares it
     * case-sensitively — so the same lookup behaved differently in production
     * and in dev. That was observed, not theorised: before this was pinned, a
     * lookup for `sub-abc` returned a row stored as `Sub-ABC` on MySQL 8.4 and
     * returned nothing on SQLite.
     *
     * A subject identifier is an opaque token minted by the provider; `a` and
     * `A` are simply different identifiers, and treating them as equal would
     * let one provider account resolve to another's local user. $email above is
     * the deliberate opposite: addresses ARE case-insensitive, which is why
     * they are normalised on write instead of compared loosely on read.
     */
    #[ORM\Column(length: 191, options: ['collation' => 'utf8mb4_bin'])]
    private string $providerUserId;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, string $provider, string $providerUserId, \DateTimeImmutable $createdAt)
    {
        $this->user = $user;
        $this->provider = $provider;
        $this->providerUserId = $providerUserId;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getProviderUserId(): string
    {
        return $this->providerUserId;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    /**
     * Normalised through the same seam as User::$email, because Plan 3b's
     * linking rule compares a provider-verified address against existing
     * accounts. If Google hands back `Bob@example.com` for an account stored as
     * `bob@example.com`, any direct comparison between the two would fail and
     * OAuth would create a second, orphaned pending account instead of linking
     * to the rightful owner.
     *
     * Note this is the setter, not the constructor: unlike User, the address
     * here is not a constructor argument, so the setter is the only write seam.
     *
     * Deliberately NOT applied to $providerUserId — provider subject
     * identifiers are opaque tokens that may be case-significant, and the
     * uniq_identity_provider_uid index covers (provider, provider_user_id)
     * only. That index is unaffected by this change.
     */
    public function setEmail(?string $email): void
    {
        $this->email = null === $email ? null : User::normalizeEmail($email);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
