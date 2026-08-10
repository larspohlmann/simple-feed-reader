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
class AiProviderSettings
{
    public const int MAX_BATCH_CONCURRENCY = 4;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $name = null;

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

    /**
     * The chosen model's context window as /models reported it at choose time,
     * tokens. Null when the provider did not report one. Cleared with the model
     * on replaceConnection() — a new endpoint may be a different gateway.
     */
    #[ORM\Column(nullable: true)]
    private ?int $modelContextWindow = null;

    /**
     * Whether the recommendation call asks the provider not to reason. Default
     * true: ranking never needs a thinking phase, and a reasoning model that
     * reasons here is pure cost (#320/#323). A strict endpoint that rejects the
     * `reasoning` field — a direct OpenAI URL — turns it off.
     */
    #[ORM\Column(options: ['default' => 1])]
    private bool $suppressReasoning = true;

    /**
     * How many batch calls a run may send at once for this connection (#344).
     * Default 1: sequential, identical to the pre-#344 behaviour, so
     * parallelism is strictly opt-in per connection. A single-GPU local model
     * gains nothing from a higher value and the low ceiling keeps a wave from
     * a memory stampede; a hosted provider gets a real wall-clock cut. The
     * range is enforced at the API (SetBatchConcurrencyRequest); this column
     * is a plain int so a value written straight to the row is still read back.
     */
    #[ORM\Column(options: ['default' => 1])]
    private int $batchConcurrency = 1;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $verifiedAt = null;

    /**
     * A row is normally born from a live call to the provider that succeeded, so
     * the first save is a verification like every later one and stamps
     * $verifiedAt too. The one exception is a duplicate (see
     * AiProviderConfigurator::duplicateConfiguration): it reuses an
     * already-verified sibling's credentials and carries that row's $verifiedAt
     * across without a fresh call. Either way the caller passes the timestamp
     * in. Delegating to replaceConnection() keeps the two paths from drifting:
     * whatever a replacement writes, a creation writes.
     */
    public function __construct(
        User $user,
        ?string $name,
        string $baseUrl,
        SealedApiKey $sealed,
        string $apiKeyHint,
        \DateTimeImmutable $verifiedAt,
    ) {
        $this->user = $user;
        $this->name = $name;
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

    public function getName(): ?string
    {
        return $this->name;
    }

    public function rename(?string $name): void
    {
        $this->name = $name;
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

    public function getModelContextWindow(): ?int
    {
        return $this->modelContextWindow;
    }

    public function hasModel(): bool
    {
        return null !== $this->model;
    }

    public function suppressesReasoning(): bool
    {
        return $this->suppressReasoning;
    }

    public function setSuppressReasoning(bool $suppressReasoning): void
    {
        $this->suppressReasoning = $suppressReasoning;
    }

    public function batchConcurrency(): int
    {
        return $this->batchConcurrency;
    }

    public function setBatchConcurrency(int $batchConcurrency): void
    {
        $this->batchConcurrency = $batchConcurrency;
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
        $this->modelContextWindow = null;
        $this->verifiedAt = $verifiedAt;
    }

    public function chooseModel(string $model, \DateTimeImmutable $verifiedAt, ?int $contextWindow): void
    {
        $this->model = $model;
        $this->modelContextWindow = $contextWindow;
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
