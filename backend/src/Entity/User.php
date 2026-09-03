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
     * When this account proved it can read mail at its address: a verify-email
     * token was consumed, or an OIDC provider vouched for a real address (#636).
     * Null means unverified — the digest will not mail an unverified address.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $emailVerifiedAt = null;

    /**
     * When this account last had a token issued to it. Null means "never
     * signed in", which the admin list renders as such and the dormancy rule
     * treats as an account that was created and then abandoned.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    /**
     * When the password hash last changed — what binds an issued JWT to a
     * password.
     *
     * JWTs here are stateless, 7-day TTL, no refresh flow. The Doctrine provider
     * reloads the user each request, so a STATUS change (suspension) revokes
     * immediately, but a password change touched nothing the token was checked
     * against: a phished user who reset their password evicted nobody — the
     * attacker's token stayed live for a week. Password reset is the canonical
     * compromise-recovery action, so this closes that gap.
     *
     * App\Security\PasswordChangeTokenInvalidator rejects any token whose `iat`
     * is older than this. Nullable and additive: rows that predate the column
     * have no recorded change, and null correctly revokes nothing.
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

    /** @see AccountLimits */
    #[ORM\Embedded(class: AccountLimits::class, columnPrefix: false)]
    private AccountLimits $accountLimits;

    /**
     * Per-account settings. The constructor creates the row, so every creation
     * path gets one without knowing about preferences.
     *
     * Nullable only because Doctrine hydration bypasses the constructor: a
     * hydrated row without preferences is a corrupt row, not a supported
     * state, and getPreferences() says so.
     */
    #[ORM\OneToOne(mappedBy: 'user', cascade: ['persist'], orphanRemoval: true)]
    private ?Preferences $preferences = null;

    /**
     * The one configuration AI features use. A pointer, not a per-row flag, so
     * the model cannot say two configurations are active at once. No inverse
     * Collection of every configuration an account owns — AiProviderSettingsRepository
     * already answers that (findAllForUser()/countForUser()), so a second,
     * always-in-sync path wasn't worth the field. ON DELETE SET NULL is the
     * database floor here; AiProviderConfigurator clears it explicitly before
     * removing the active row. The rows themselves cascade on account deletion
     * through user_ai_settings.user_id's own FK ON DELETE CASCADE — see AccountDeleter.
     */
    #[ORM\ManyToOne(targetEntity: AiProviderSettings::class)]
    #[ORM\JoinColumn(name: 'active_ai_config_id', nullable: true, onDelete: 'SET NULL')]
    private ?AiProviderSettings $activeAiProviderSettings = null;

    #[ORM\OneToOne(mappedBy: 'user', targetEntity: RecommendationSettings::class, cascade: ['remove'])]
    private ?RecommendationSettings $recommendationSettings = null;

    public function __construct(string $email, \DateTimeImmutable $createdAt)
    {
        $email = self::normalizeEmail($email);

        if ('' === $email) {
            throw new \InvalidArgumentException('User email must not be empty.');
        }

        $this->email = $email;
        $this->createdAt = $createdAt;
        $this->preferences = new Preferences($this);
        $this->accountLimits = new AccountLimits();
    }

    /**
     * The single definition of what makes two addresses the same account.
     *
     * Exists because the storage layer disagrees with itself: SQLite (dev/test)
     * compares VARCHAR case-sensitively, while MySQL production runs a utf8mb4
     * _ci collation (also governing the uniq_user_email index) that does not.
     * Left alone, `Bob@example.com` opens a second account on SQLite and
     * collides on MySQL — CI green, production silently refusing a signup.
     *
     * Normalising to lowercase here, not at each call site, keeps the entity,
     * repository and security provider from drifting apart; every lookup path
     * must run input through this before comparing.
     *
     * strtolower, not mb_strtolower: Assert\Email in html5 mode already refuses
     * non-ASCII addresses, and strtolower is locale-independent in PHP 8 — no
     * Turkish-dotless-i hazard to inherit.
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

    public function getEmailVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->emailVerifiedAt;
    }

    public function isEmailVerified(): bool
    {
        return null !== $this->emailVerifiedAt;
    }

    /** Stamps the first verification only; re-verifying never moves the instant. */
    public function markEmailVerified(\DateTimeImmutable $verifiedAt): void
    {
        $this->emailVerifiedAt ??= $verifiedAt;
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function setLastLoginAt(\DateTimeImmutable $lastLoginAt): void
    {
        $this->lastLoginAt = $lastLoginAt;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    public function getTrialEndsAt(): ?\DateTimeImmutable
    {
        return $this->accountLimits->getTrialEndsAt();
    }

    public function setTrialEndsAt(?\DateTimeImmutable $trialEndsAt): void
    {
        $this->accountLimits->setTrialEndsAt($trialEndsAt);
    }

    public function getMaxSubscriptions(): ?int
    {
        return $this->accountLimits->getMaxSubscriptions();
    }

    public function setMaxSubscriptions(?int $maxSubscriptions): void
    {
        $this->accountLimits->setMaxSubscriptions($maxSubscriptions);
    }

    /**
     * Mirrors the getUserIdentifier() guard: the invariant is set in the
     * constructor, and Doctrine hydration bypasses it, so it is re-checked
     * here where callers actually depend on it.
     */
    public function getPreferences(): Preferences
    {
        if (null === $this->preferences) {
            throw new \LogicException('User has no preferences row; the stored row is corrupt.');
        }

        return $this->preferences;
    }

    /** Null until the account activates a configuration — see AiProviderSettings. */
    public function getActiveAiProviderSettings(): ?AiProviderSettings
    {
        return $this->activeAiProviderSettings;
    }

    /**
     * For AiProviderConfigurator only, which owns every write to the pointer.
     *
     * This is the owning side, but MeJson and every other reader takes the
     * User instance a request already loaded rather than re-querying, so a
     * caller that flips the pointer must also update it here — otherwise the
     * same User instance would keep reporting the state it had before the
     * write until the next request hydrated it fresh.
     */
    public function setActiveAiProviderSettings(?AiProviderSettings $settings): void
    {
        $this->activeAiProviderSettings = $settings;
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
