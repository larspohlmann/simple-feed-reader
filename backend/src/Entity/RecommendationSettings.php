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

    /**
     * The reader's inferred preference profile (#493): distilled by a later
     * pipeline phase, not by this settings row's own writer path. Read-only
     * from the settings API; only RecommendationSettingsWriter::storeProfile()
     * sets it.
     */
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

    /**
     * How many days back a run's candidate pool reaches (#386). The cap in
     * candidatePoolSize applies inside this window.
     */
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
     * How often the background worker (or the maintenance cron endpoint)
     * starts a fresh run for this account. null means "only manually" (#333).
     */
    #[ORM\Column(nullable: true)]
    private ?int $autoGenerateIntervalHours = null;

    /**
     * Whether the reader UI explains each pick — the one-line reason and the
     * score beside it, which travel together (#541, widened to the score by
     * #576). Debug mode does not reach either.
     */
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
        $this->favoritesCap = $values->favoritesCap;
        $this->keptCap = $values->keptCap;
        $this->viewedCap = $values->viewedCap;
        $this->candidatePoolSize = $values->candidatePoolSize;
        $this->lookbackDays = $values->lookbackDays;
        $this->picksLimit = $values->picksLimit;
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
            favoritesCap: $this->favoritesCap,
            keptCap: $this->keptCap,
            viewedCap: $this->viewedCap,
            candidatePoolSize: $this->candidatePoolSize,
            lookbackDays: $this->lookbackDays,
            picksLimit: $this->picksLimit,
            contextWindow: $this->contextWindow,
            batchSize: $this->batchSize,
            debugEnabled: $this->debugEnabled,
            autoGenerateIntervalHours: $this->autoGenerateIntervalHours,
            showReasons: $this->showReasons,
        );
    }
}
