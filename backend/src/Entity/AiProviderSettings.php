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
    /**
     * The hard ceiling on one tick's wave of provider calls. Raised from 4 to
     * 8: a hosted provider answers a wave of that width without complaint and
     * a long run finishes in half the ticks. A local model server stays at its
     * own useful value, which is usually 1 — this is a ceiling, not a target,
     * and the default remains 1. A poll tick never reaches it anyway
     * (RecommendationRunAdvancer::POLL_MAX_CONCURRENCY), so the width only
     * applies where a process owns its own time: the worker.
     */
    public const int MAX_BATCH_CONCURRENCY = 8;

    /**
     * Does not collide with RecommendationPromptBuilder::MINIMUM_BATCH_SIZE
     * (10), even though a configured value can sit below it. That constant only
     * floors packBatches()'s token-budget-driven early split (needs 10+
     * candidates before an over-budget line starts a new batch); this value is
     * the hard per-batch ceiling instead (`atCapacity` in packBatches()), closing
     * a batch the moment it holds `maximumBatchSize` candidates regardless of
     * token budget. A configured cap below 10 always hits that ceiling first, so
     * the token-budget floor never applies — verified directly: caps of 5, 7, 9
     * against 40 candidates came back sized exactly to the cap every time.
     */
    public const int MINIMUM_BATCH_SIZE = 5;

    /**
     * A sanity bound against a typo, not a quality bound: the token budget is
     * the real guard on how large a batch may be.
     */
    public const int MAXIMUM_BATCH_SIZE = 200;

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

    #[ORM\Embedded(class: RunTuning::class, columnPrefix: false)]
    private RunTuning $runTuning;

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
        $this->runTuning = new RunTuning();
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
        return $this->runTuning->batchConcurrency();
    }

    public function setBatchConcurrency(int $batchConcurrency): void
    {
        $this->runTuning->setBatchConcurrency($batchConcurrency);
    }

    public function isSlowModel(): bool
    {
        return $this->runTuning->isSlowModel();
    }

    public function setSlowModel(bool $slowModel): void
    {
        $this->runTuning->setSlowModel($slowModel);
    }

    public function maxBatchSize(): ?int
    {
        return $this->runTuning->maxBatchSize();
    }

    public function setMaxBatchSize(?int $maxBatchSize): void
    {
        $this->runTuning->setMaxBatchSize($maxBatchSize);
    }

    /**
     * For AiProviderConfigurator::duplicateConfiguration(): the copy should
     * start out driven the same way as the connection it was copied from,
     * not reset to the defaults.
     */
    public function copyRunTuningFrom(self $source): void
    {
        $this->runTuning->copyFrom($source->runTuning);
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
