<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\UserStatus;
use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
#[ORM\UniqueConstraint(name: 'uniq_user_email', columns: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column(nullable: true)]
    private ?string $passwordHash = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    #[ORM\Column(length: 30, enumType: UserStatus::class)]
    private UserStatus $status = UserStatus::PendingVerification;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $approvedAt = null;

    public function __construct(string $email, \DateTimeImmutable $createdAt)
    {
        $email = self::normalizeEmail($email);

        if ('' === $email) {
            throw new \InvalidArgumentException('User email must not be empty.');
        }

        $this->email = $email;
        $this->createdAt = $createdAt;
    }

    /**
     * The single definition of what makes two addresses the same account.
     *
     * This exists because the storage layer does not agree with itself: SQLite
     * (dev and test) compares VARCHAR case-sensitively, while MySQL production
     * runs a utf8mb4 _ci collation that does not — and that collation also
     * governs the uniq_user_email index. Left alone, `Bob@example.com` opens a
     * second account on SQLite and collides on MySQL, so CI can be green while
     * production silently refuses a signup.
     *
     * Normalising to lowercase on the way in makes the two engines agree, and
     * doing it here — rather than at each call site — is what keeps the entity,
     * the repository and the security provider from drifting apart. Every
     * lookup path must run input through this before comparing.
     *
     * strtolower, not mb_strtolower: Assert\Email in html5 mode already refuses
     * non-ASCII addresses, and strtolower is locale-independent in PHP 8, so
     * there is no Turkish-dotless-i hazard to inherit.
     */
    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getPasswordHash(): ?string
    {
        return $this->passwordHash;
    }

    public function setPasswordHash(?string $passwordHash): void
    {
        $this->passwordHash = $passwordHash;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): void
    {
        $this->roles = $roles;
    }

    public function getStatus(): UserStatus
    {
        return $this->status;
    }

    public function setStatus(UserStatus $status): void
    {
        $this->status = $status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getApprovedAt(): ?\DateTimeImmutable
    {
        return $this->approvedAt;
    }

    public function setApprovedAt(?\DateTimeImmutable $approvedAt): void
    {
        $this->approvedAt = $approvedAt;
    }

    /**
     * The constructor rejects an empty email, but Doctrine hydration bypasses
     * the constructor, so the invariant is re-checked here where the security
     * layer contract (a non-empty identifier) actually depends on it.
     */
    public function getUserIdentifier(): string
    {
        if ('' === $this->email) {
            throw new \LogicException('User has an empty email; the stored row is corrupt.');
        }

        return $this->email;
    }

    public function getPassword(): ?string
    {
        return $this->passwordHash;
    }

    /**
     * No transient credentials are held on the entity - the password only ever
     * exists as a hash in $passwordHash - so there is nothing to erase.
     *
     * The #[\Deprecated] attribute is what stops Symfony's AuthenticatorManager
     * from triggering a 7.3 deprecation (and from calling this at all).
     *
     * @deprecated since Symfony 7.3, nothing to erase
     */
    #[\Deprecated(since: 'symfony/security-core 7.3')]
    public function eraseCredentials(): void
    {
    }
}
