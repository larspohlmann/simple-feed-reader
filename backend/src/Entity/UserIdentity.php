<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserIdentityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserIdentityRepository::class)]
#[ORM\Table(name: 'user_identity')]
#[ORM\UniqueConstraint(name: 'uniq_identity_provider_uid', columns: ['provider', 'provider_user_id'])]
final class UserIdentity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Default collation on purpose: a closed vocabulary our own code writes, unlike $providerUserId. */
    #[ORM\Column(length: 30)]
    private string $provider;

    /**
     * `utf8mb4_bin`, pinned: MySQL's default collation is case-insensitive, SQLite's is not, and a subject that
     * differs only in case is another provider account. Never normalise it.
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

    /** Normalised like User::$email, so linking by address ignores the case a provider sends. */
    public function setEmail(?string $email): void
    {
        $this->email = null === $email ? null : User::normalizeEmail($email);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
