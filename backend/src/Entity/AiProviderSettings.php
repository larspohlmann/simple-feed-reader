<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AiProviderSettingsRepository;
use App\Service\Ai\Crypto\SealedApiKey;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One account's AI provider. Unlike Preferences, this row is NOT created with
 * the account: most accounts never configure a provider, and "no row" says
 * "not configured" without a nullable flag.
 *
 * The row holds no readable secret. `apiKeyHint` is the last four characters
 * in clear text, on purpose, so the settings page can say which key is stored.
 */
#[ORM\Entity(repositoryClass: AiProviderSettingsRepository::class)]
#[ORM\Table(name: 'user_ai_settings')]
#[ORM\UniqueConstraint(name: 'uniq_ai_settings_user', columns: ['user_id'])]
class AiProviderSettings
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'aiProviderSettings')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 512)]
    private string $baseUrl;

    #[ORM\Column(length: 1024)]
    private string $apiKeyCiphertext;

    #[ORM\Column(length: 64)]
    private string $apiKeyNonce;

    #[ORM\Column(length: 64)]
    private string $apiKeySalt;

    #[ORM\Column(length: 8)]
    private string $apiKeyHint;

    #[ORM\Column(options: ['default' => 1])]
    private int $keyVersion;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $model = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $verifiedAt = null;

    /**
     * A row only ever exists because a live call to the provider succeeded, so
     * the first save is a verification like every later one and stamps
     * $verifiedAt too. Delegating to replaceConnection() keeps the two paths
     * from drifting: whatever a replacement writes, a creation writes.
     */
    public function __construct(
        User $user,
        string $baseUrl,
        SealedApiKey $sealed,
        string $apiKeyHint,
        \DateTimeImmutable $verifiedAt,
    ) {
        $this->user = $user;
        $this->replaceConnection($baseUrl, $sealed, $apiKeyHint, $verifiedAt);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getApiKeyHint(): string
    {
        return $this->apiKeyHint;
    }

    public function getSealedApiKey(): SealedApiKey
    {
        return new SealedApiKey(
            $this->apiKeyCiphertext,
            $this->apiKeyNonce,
            $this->apiKeySalt,
            $this->keyVersion,
        );
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function hasModel(): bool
    {
        return null !== $this->model;
    }

    public function getVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->verifiedAt;
    }

    /**
     * A new endpoint or a new key invalidates the chosen model: the identifier
     * that existed at the old provider carries no promise at the new one, and
     * keeping it would let `ready` claim a model the provider never offered.
     */
    public function replaceConnection(
        string $baseUrl,
        SealedApiKey $sealed,
        string $apiKeyHint,
        \DateTimeImmutable $verifiedAt,
    ): void {
        $this->baseUrl = $baseUrl;
        $this->apiKeyHint = $apiKeyHint;
        $this->applySealedKey($sealed);
        $this->model = null;
        $this->verifiedAt = $verifiedAt;
    }

    public function chooseModel(string $model, \DateTimeImmutable $verifiedAt): void
    {
        $this->model = $model;
        $this->verifiedAt = $verifiedAt;
    }

    private function applySealedKey(SealedApiKey $sealed): void
    {
        $this->apiKeyCiphertext = $sealed->ciphertext;
        $this->apiKeyNonce = $sealed->nonce;
        $this->apiKeySalt = $sealed->salt;
        $this->keyVersion = $sealed->version;
    }
}
