<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\RecommendationBatchSize;
use App\Repository\RecommendationSettingsRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * No row = all defaults (the DEFAULT_ constants below); the row exists
 * only once the user saves the settings form.
 */
#[ORM\Entity(repositoryClass: RecommendationSettingsRepository::class)]
#[ORM\Table(name: 'user_recommendation_settings')]
#[ORM\UniqueConstraint(name: 'uniq_recommendation_settings_user', columns: ['user_id'])]
final class RecommendationSettings
{
    public const int DEFAULT_FAVORITES_CAP = 40;
    public const int DEFAULT_KEPT_CAP = 40;
    public const int DEFAULT_VIEWED_CAP = 80;
    public const int DEFAULT_CANDIDATE_POOL_SIZE = 500;
    public const int DEFAULT_LOOKBACK_DAYS = 2;
    public const int DEFAULT_PICKS_LIMIT = 50;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'recommendationSettings')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $guidancePrompt = null;

    /** The distilled preference profile; only RecommendationSettingsWriter::storeProfile() changes it. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $profileText = null;

    #[ORM\Column(options: ['default' => self::DEFAULT_FAVORITES_CAP])]
    private int $favoritesCap = self::DEFAULT_FAVORITES_CAP;

    #[ORM\Column(options: ['default' => self::DEFAULT_KEPT_CAP])]
    private int $keptCap = self::DEFAULT_KEPT_CAP;

    #[ORM\Column(options: ['default' => self::DEFAULT_VIEWED_CAP])]
    private int $viewedCap = self::DEFAULT_VIEWED_CAP;

    #[ORM\Column(options: ['default' => self::DEFAULT_CANDIDATE_POOL_SIZE])]
    private int $candidatePoolSize = self::DEFAULT_CANDIDATE_POOL_SIZE;

    /** How many days back a run's candidate pool reaches; candidatePoolSize caps the pool inside that window. */
    #[ORM\Column(options: ['default' => self::DEFAULT_LOOKBACK_DAYS])]
    private int $lookbackDays = self::DEFAULT_LOOKBACK_DAYS;

    #[ORM\Column(options: ['default' => self::DEFAULT_PICKS_LIMIT])]
    private int $picksLimit = self::DEFAULT_PICKS_LIMIT;

    #[ORM\Column(nullable: true)]
    private ?int $contextWindow = null;

    #[ORM\Column(length: 10, enumType: RecommendationBatchSize::class, options: ['default' => 'medium'])]
    private RecommendationBatchSize $batchSize = RecommendationBatchSize::Medium;

    #[ORM\Column(options: ['default' => false])]
    private bool $debugEnabled = false;

    /**
     * How often the background worker (or the maintenance cron endpoint) starts a fresh run for this account; null
     * means only manually.
     */
    #[ORM\Column(nullable: true)]
    private ?int $autoGenerateIntervalHours = null;

    /** Whether the reader UI shows each pick's one-line reason and, beside it, its score. */
    #[ORM\Column(options: ['default' => false])]
    private bool $showReasons = false;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function update(RecommendationSettingsValues $values): void
    {
        $this->guidancePrompt = $values->guidancePrompt;
        $this->profileText = $values->profileText;
        $this->favoritesCap = $values->historyCaps->favorites;
        $this->keptCap = $values->historyCaps->kept;
        $this->viewedCap = $values->historyCaps->viewed;
        $this->candidatePoolSize = $values->poolLimits->candidatePoolSize;
        $this->lookbackDays = $values->poolLimits->lookbackDays;
        $this->picksLimit = $values->poolLimits->picksLimit;
        $this->contextWindow = $values->contextWindow;
        $this->batchSize = $values->batchSize;
        $this->debugEnabled = $values->debugEnabled;
        $this->autoGenerateIntervalHours = $values->autoGenerateIntervalHours;
        $this->showReasons = $values->showReasons;
    }

    public function values(): RecommendationSettingsValues
    {
        return new RecommendationSettingsValues(
            guidancePrompt: $this->guidancePrompt,
            profileText: $this->profileText,
            historyCaps: new RecommendationHistoryCaps($this->favoritesCap, $this->keptCap, $this->viewedCap),
            poolLimits: new RecommendationPoolLimits($this->candidatePoolSize, $this->lookbackDays, $this->picksLimit),
            contextWindow: $this->contextWindow,
            batchSize: $this->batchSize,
            debugEnabled: $this->debugEnabled,
            autoGenerateIntervalHours: $this->autoGenerateIntervalHours,
            showReasons: $this->showReasons,
        );
    }
}
