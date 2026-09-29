<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserPasskeyRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserPasskeyRepository::class)]
#[ORM\Table(name: 'user_passkey')]
#[ORM\UniqueConstraint(name: 'uniq_passkey_credential_id', columns: ['credential_id'])]
final class UserPasskey
{
    use PersistedId;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /**
     * `_bin` collation pinned, as on {@see UserIdentity::$providerUserId}: an opaque credential id compares
     * case-sensitively on both engines, or `a` could resolve to `A`'s row.
     */
    #[ORM\Column(length: 255, options: ['collation' => 'utf8mb4_bin'])]
    private string $credentialId;

    /**
     * `_bin`, as $credentialId. 32 random bytes, base64url: never the e-mail (authenticators sync the handle to a
     * password manager) nor the account id (it would leak the account count and order).
     */
    #[ORM\Column(length: 64, options: ['collation' => 'utf8mb4_bin'])]
    private string $userHandle;

    #[ORM\Column(type: Types::TEXT)]
    private string $publicKey;

    #[ORM\Column]
    private int $signatureCounter;

    #[ORM\Column(length: 36, nullable: true)]
    private ?string $aaguid;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $transports;

    #[ORM\Column(length: 100)]
    private string $label;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(User $user, PasskeyRegistration $registration)
    {
        $this->user = $user;
        $this->credentialId = $registration->credentialId;
        $this->userHandle = $registration->userHandle;
        $this->publicKey = $registration->publicKey;
        $this->signatureCounter = $registration->signatureCounter;
        $this->aaguid = $registration->aaguid;
        $this->transports = $registration->transports;
        $this->label = $registration->label;
        $this->createdAt = $registration->registeredAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getCredentialId(): string
    {
        return $this->credentialId;
    }

    public function getUserHandle(): string
    {
        return $this->userHandle;
    }

    public function getPublicKey(): string
    {
        return $this->publicKey;
    }

    public function getSignatureCounter(): int
    {
        return $this->signatureCounter;
    }

    public function getAaguid(): ?string
    {
        return $this->aaguid;
    }

    /** @return list<string> */
    public function getTransports(): array
    {
        return $this->transports;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    /**
     * The only mutator. Clock and counter move together: a stamped use with a stale counter would let a cloned
     * authenticator replay an old signature undetected.
     */
    public function recordUse(\DateTimeImmutable $at, int $signatureCounter): void
    {
        $this->lastUsedAt = $at;
        $this->signatureCounter = $signatureCounter;
    }
}
