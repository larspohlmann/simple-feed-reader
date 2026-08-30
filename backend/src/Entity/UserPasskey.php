<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserPasskeyRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserPasskeyRepository::class)]
#[ORM\Table(name: 'user_passkey')]
#[ORM\UniqueConstraint(name: 'uniq_passkey_credential_id', columns: ['credential_id'])]
class UserPasskey
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /**
     * `_bin` collation, pinned explicitly, for the same reason as
     * {@see UserIdentity::$providerUserId}: without it MySQL inherits the
     * table's default collation and compares this column case-insensitively,
     * while SQLite compares it case-sensitively.
     *
     * A credential id is an opaque token minted by the authenticator, not a
     * word; `a` and `A` are simply different identifiers, and treating them as
     * equal would let one credential resolve to another's row.
     */
    #[ORM\Column(length: 255, options: ['collation' => 'utf8mb4_bin'])]
    private string $credentialId;

    /**
     * `_bin` collation, pinned for the same reason as $credentialId above.
     *
     * `Webauthn\CredentialRecord` requires a non-nullable `userHandle`,
     * checked on every assertion. The handle is 32 random bytes,
     * base64url-encoded. It is deliberately NOT the e-mail address — the
     * authenticator stores the handle and syncs it to the user's password
     * manager, so it must carry no personal data — and NOT the numeric account
     * id, because that would leak how many accounts this instance has and in
     * what order they were made.
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

    /**
     * @param list<string> $transports
     */
    public function __construct(
        User $user,
        string $credentialId,
        string $userHandle,
        string $publicKey,
        int $signatureCounter,
        ?string $aaguid,
        array $transports,
        string $label,
        \DateTimeImmutable $createdAt,
    ) {
        $this->user = $user;
        $this->credentialId = $credentialId;
        $this->userHandle = $userHandle;
        $this->publicKey = $publicKey;
        $this->signatureCounter = $signatureCounter;
        $this->aaguid = $aaguid;
        $this->transports = $transports;
        $this->label = $label;
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
     * The only mutator. Sets the clock and the counter together, because a
     * use that advanced one without the other would be a half-written row:
     * a stamped `lastUsedAt` with a stale counter would let a cloned
     * authenticator replay an old signature undetected.
     */
    public function recordUse(\DateTimeImmutable $at, int $signatureCounter): void
    {
        $this->lastUsedAt = $at;
        $this->signatureCounter = $signatureCounter;
    }
}
