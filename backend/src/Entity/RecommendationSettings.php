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

    #[ORM\Embedded(class: StoredProfile::class, columnPrefix: false)]
    private StoredProfile $storedProfile;

    #[ORM\Column(options: ['default' => self::DEFAULT_FAVORITES_CAP])]
    private int $favoritesCap = self::DEFAULT_FAVORITES_CAP;

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

    #[ORM\Embedded(class: ProfileTuning::class, columnPrefix: false)]
    private ProfileTuning $profileTuning;

    /** The connection that builds the profile; null means the active one. */
    #[ORM\ManyToOne(targetEntity: AiProviderSettings::class)]
    #[ORM\JoinColumn(name: 'profile_connection_id', nullable: true, onDelete: 'SET NULL')]
    private ?AiProviderSettings $profileConnection = null;

    /** Whether the reader UI shows each pick's score and, where the engine writes one, its reason. */
    #[ORM\Column(name: 'show_reasons', options: ['default' => false])]
    private bool $showScoreAndReasons = false;

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->storedProfile = StoredProfile::none();
        $this->profileTuning = ProfileTuning::defaults();
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function update(RecommendationSettingsValues $values): void
    {
        $this->guidancePrompt = $values->guidancePrompt;
        $this->favoritesCap = $values->favoritesCap;
        $this->candidatePoolSize = $values->poolLimits->candidatePoolSize;
        $this->lookbackDays = $values->poolLimits->lookbackDays;
        $this->picksLimit = $values->poolLimits->picksLimit;
        $this->contextWindow = $values->contextWindow;
        $this->batchSize = $values->batchSize;
        $this->debugEnabled = $values->debugEnabled;
        $this->autoGenerateIntervalHours = $values->autoGenerateIntervalHours;
        $this->showScoreAndReasons = $values->showScoreAndReasons;
    }

    public function values(): RecommendationSettingsValues
    {
        return new RecommendationSettingsValues(
            guidancePrompt: $this->guidancePrompt,
            favoritesCap: $this->favoritesCap,
            poolLimits: new RecommendationPoolLimits($this->candidatePoolSize, $this->lookbackDays, $this->picksLimit),
            contextWindow: $this->contextWindow,
            batchSize: $this->batchSize,
            debugEnabled: $this->debugEnabled,
            autoGenerateIntervalHours: $this->autoGenerateIntervalHours,
            showScoreAndReasons: $this->showScoreAndReasons,
        );
    }

    public function updateProfileSettings(ProfileSettingsValues $values): void
    {
        $this->profileTuning = new ProfileTuning($values->intervalHours, $values->keptCap, $values->viewedCap);
        $this->profileConnection = $values->connection;
    }

    public function profileSettings(): ProfileSettingsValues
    {
        return new ProfileSettingsValues(
            $this->profileTuning->getIntervalHours(),
            $this->profileConnection,
            $this->profileTuning->getKeptCap(),
            $this->profileTuning->getViewedCap(),
        );
    }

    public function storeProfile(StoredProfile $profile): void
    {
        $this->storedProfile = $profile;
    }

    public function getStoredProfile(): StoredProfile
    {
        return $this->storedProfile;
    }

    public function forgetProfileConnection(AiProviderSettings $connection): void
    {
        if ($this->profileConnection === $connection) {
            $this->profileConnection = null;
        }
    }
}
