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
final class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    use PersistedId;

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
     * token was consumed, or an OIDC provider vouched for a real address.
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
     * When the password hash last changed: every JWT issued before it is rejected, so a reset evicts a stolen token;
     * null (a row older than the column) revokes nothing. Why: docs/security.md#password-change
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $passwordChangedAt = null;

    /**
     * The language of this account's mails ('en' | 'de'), captured from the UI at registration. API responses never
     * vary by it.
     */
    #[ORM\Column(length: 5, options: ['default' => 'en'])]
    private string $locale = 'en';

    /** @see AccountLimits */
    #[ORM\Embedded(class: AccountLimits::class, columnPrefix: false)]
    private AccountLimits $accountLimits;

    /**
     * Created by the constructor, so every creation path has one. Nullable only because hydration bypasses the
     * constructor: a row without preferences is corrupt, and getPreferences() says so.
     */
    #[ORM\OneToOne(mappedBy: 'user', cascade: ['persist'], orphanRemoval: true)]
    private ?Preferences $preferences = null;

    /**
     * The one configuration AI features use: a pointer, so two cannot be active at once. AiProviderConfigurator clears
     * it before removing that row; ON DELETE SET NULL is only the database floor.
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
     * The one definition of "same address"; every lookup runs input through it. SQLite compares case-sensitively and
     * MySQL's _ci collation does not, so skipping it opens a second account on one engine and collides on the other.
     * strtolower is enough: Assert\Email's html5 mode refuses non-ASCII addresses.
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

    /** $changedAt is required: a hash rotated without the stamp would leave every issued token valid. */
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

    public function isAdmin(): bool
    {
        return \in_array('ROLE_ADMIN', $this->roles, true);
    }

    public function getStatus(): UserStatus
    {
        return $this->status;
    }

    public function approve(\DateTimeImmutable $approvedAt): void
    {
        $this->status = UserStatus::Active;
        $this->approvedAt = $approvedAt;
    }

    public function queueForApproval(): void
    {
        $this->status = UserStatus::PendingApproval;
    }

    public function reject(): void
    {
        $this->status = UserStatus::Rejected;
    }

    public function suspend(): void
    {
        $this->status = UserStatus::Suspended;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getApprovedAt(): ?\DateTimeImmutable
    {
        return $this->approvedAt;
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
     * For AiProviderConfigurator only, which owns every write to the pointer and must also set it here: MeJson and
     * other readers use the User the request already loaded, not a fresh query.
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
     * #[\Deprecated] stops Symfony 7.3's AuthenticatorManager from calling this and triggering its deprecation.
     *
     * @deprecated since Symfony 7.3, nothing to erase
     */
    #[\Deprecated(since: 'symfony/security-core 7.3')]
    public function eraseCredentials(): void
    {
    }
}
