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

    /**
     * When the password hash last changed. This is what binds an issued JWT to
     * a password, and it exists because nothing else did.
     *
     * JWTs here are stateless bearer tokens with a 7-day TTL and no refresh
     * flow. The Doctrine provider reloads the user on every request, so a
     * STATUS change (suspension) revokes immediately — but a password change
     * touched nothing the token was checked against. A phished user who reset
     * their password evicted nobody: the attacker's token stayed live for a
     * week while the victim believed they had recovered. Password reset is the
     * canonical compromise-recovery action, so that was the one thing it had to
     * do and did not.
     *
     * App\Security\PasswordChangeTokenInvalidator rejects any token whose `iat`
     * is strictly older than this. Nullable because it is additive: rows that
     * predate the column have no recorded change, and a null here means "never
     * changed since this was introduced", which correctly revokes nothing.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $passwordChangedAt = null;

    /**
     * The recipient language for this account's emails ('en' | 'de'), captured
     * from the UI at registration. The API itself is locale-agnostic; only the
     * transactional mails vary by language.
     */
    // The DB default backfills rows that predate the column (see the migration);
    // declaring it here keeps the mapping in sync with that DDL.
    #[ORM\Column(length: 5, options: ['default' => 'en'])]
    private string $locale = 'en';

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

    /**
     * $changedAt is mandatory on purpose, and injected rather than read from
     * the system clock here (services never call `new \DateTimeImmutable`).
     *
     * The revocation guarantee is only as good as the stamp: a call site that
     * rotates the hash without recording when would silently leave every
     * previously issued token valid — exactly the bug this column was added to
     * close, reintroduced quietly. Making the parameter required means that
     * mistake does not compile.
     */
    public function setPasswordHash(?string $passwordHash, \DateTimeImmutable $changedAt): void
    {
        $this->passwordHash = $passwordHash;
        $this->passwordChangedAt = $changedAt;
    }

    public function getPasswordChangedAt(): ?\DateTimeImmutable
    {
        return $this->passwordChangedAt;
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

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
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
