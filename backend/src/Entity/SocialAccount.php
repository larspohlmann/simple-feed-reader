<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SocialAccountStatus;
use App\Enum\SocialNetwork;
use App\Repository\SocialAccountRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SocialAccountRepository::class)]
#[ORM\Table(name: 'social_account')]
#[ORM\UniqueConstraint(name: 'uniq_social_account_identity', columns: ['user_id', 'network', 'identity'])]
final class SocialAccount
{
    use PersistedId;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 16, enumType: SocialNetwork::class)]
    private SocialNetwork $network;

    #[ORM\Column(length: 255)]
    private string $identity;

    #[ORM\Column(length: 255)]
    private string $handle;

    #[ORM\Column(length: 512)]
    private string $serviceUrl;

    #[ORM\Column(length: 1024)]
    private string $credentialCiphertext;

    #[ORM\Column(length: 64)]
    private string $credentialNonce;

    #[ORM\Column(length: 64)]
    private string $credentialSalt;

    #[ORM\Column(length: 8)]
    private string $credentialHint;

    #[ORM\Column(options: ['default' => 1])]
    private int $credentialVersion;

    #[ORM\Column(length: 24, enumType: SocialAccountStatus::class)]
    private SocialAccountStatus $status = SocialAccountStatus::Connected;

    #[ORM\Column(options: ['default' => false])]
    private bool $isDefault = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $connectedAt;

    public function __construct(
        User $user,
        SocialNetwork $network,
        string $identity,
        string $handle,
        string $serviceUrl,
        SealedSecret $credential,
        string $credentialHint,
        \DateTimeImmutable $connectedAt,
    ) {
        $this->user = $user;
        $this->network = $network;
        $this->identity = $identity;
        $this->serviceUrl = $serviceUrl;
        $this->replaceCredential($handle, $credential, $credentialHint, $connectedAt);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getNetwork(): SocialNetwork
    {
        return $this->network;
    }

    public function getIdentity(): string
    {
        return $this->identity;
    }

    public function getHandle(): string
    {
        return $this->handle;
    }

    public function getServiceUrl(): string
    {
        return $this->serviceUrl;
    }

    public function getSealedCredential(): SealedSecret
    {
        return new SealedSecret(
            $this->credentialCiphertext,
            $this->credentialNonce,
            $this->credentialSalt,
            $this->credentialVersion,
        );
    }

    public function getCredentialHint(): string
    {
        return $this->credentialHint;
    }

    public function getStatus(): SocialAccountStatus
    {
        return $this->status;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function getConnectedAt(): \DateTimeImmutable
    {
        return $this->connectedAt;
    }

    public function replaceCredential(
        string $handle,
        SealedSecret $credential,
        string $credentialHint,
        \DateTimeImmutable $connectedAt,
    ): void {
        $this->handle = $handle;
        $this->credentialCiphertext = $credential->ciphertext;
        $this->credentialNonce = $credential->nonce;
        $this->credentialSalt = $credential->salt;
        $this->credentialVersion = $credential->version;
        $this->credentialHint = $credentialHint;
        $this->connectedAt = $connectedAt;
        $this->status = SocialAccountStatus::Connected;
    }

    public function markNeedsReconnect(): void
    {
        $this->status = SocialAccountStatus::NeedsReconnect;
    }

    public function becomeDefault(): void
    {
        $this->isDefault = true;
    }

    public function stopBeingDefault(): void
    {
        $this->isDefault = false;
    }
}
