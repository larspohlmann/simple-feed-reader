<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AiProviderSettingsRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One account's AI provider. Unlike Preferences, no row is created with the account: no row means "not configured".
 * No readable secret is stored; `apiKeyHint`, the key's last four characters, is clear text on purpose.
 */
#[ORM\Entity(repositoryClass: AiProviderSettingsRepository::class)]
#[ORM\Table(name: 'user_ai_settings')]
final class AiProviderSettings
{
    use PersistedId;

    /**
     * The smallest cap an account may set. It may sit below RecommendationPromptBuilder::MINIMUM_BATCH_SIZE (10): that
     * floors only the token-budget split, and the cap closes a batch first (caps 5, 7, 9 over 40 candidates held).
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
     * Default true: ranking needs no thinking phase, and a reasoning model reasoning here is pure cost (#320, #323).
     */
    #[ORM\Column(options: ['default' => 1])]
    private bool $suppressReasoning = true;

    #[ORM\Column(name: 'suppression_refused_by_model', length: 255, nullable: true)]
    private ?string $suppressionRefusedByModel = null;

    #[ORM\Embedded(class: RunTuning::class, columnPrefix: false)]
    private RunTuning $runTuning;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $verifiedAt = null;

    /**
     * The caller passes $verifiedAt: a row is normally born of a successful live call, but a duplicate
     * (AiProviderConfigurator::duplicateConfiguration) carries its sibling's. Delegates to replaceConnection().
     */
    public function __construct(
        User $user,
        ?string $name,
        string $baseUrl,
        SealedSecret $sealed,
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

    public function getSealedSecret(): SealedSecret
    {
        return new SealedSecret(
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
        $this->suppressionRefusedByModel = null;
    }

    public function recordSuppressionRefused(): void
    {
        $this->suppressionRefusedByModel = $this->model
            ?? throw new \LogicException('A connection without a model has sent no request to refuse.');
    }

    public function refusesSuppressedReasoning(): bool
    {
        return null !== $this->model && $this->model === $this->suppressionRefusedByModel;
    }

    public function getRunTuning(): RunTuning
    {
        return $this->runTuning;
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

    public function setMaxBatchSize(?int $maxBatchSize): void
    {
        $this->runTuning->setMaxBatchSize($maxBatchSize);
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
        SealedSecret $sealed,
        string $apiKeyHint,
        \DateTimeImmutable $verifiedAt,
    ): void {
        $this->baseUrl = $baseUrl;
        $this->apiKeyHint = $apiKeyHint;
        $this->applySealedKey($sealed);
        $this->model = null;
        $this->modelContextWindow = null;
        $this->suppressionRefusedByModel = null;
        $this->verifiedAt = $verifiedAt;
    }

    public function chooseModel(string $model, \DateTimeImmutable $verifiedAt, ?int $contextWindow): void
    {
        $this->model = $model;
        $this->modelContextWindow = $contextWindow;
        $this->verifiedAt = $verifiedAt;
    }

    private function applySealedKey(SealedSecret $sealed): void
    {
        $this->apiKeyCiphertext = $sealed->ciphertext;
        $this->apiKeyNonce = $sealed->nonce;
        $this->apiKeySalt = $sealed->salt;
        $this->keyVersion = $sealed->version;
    }
}
