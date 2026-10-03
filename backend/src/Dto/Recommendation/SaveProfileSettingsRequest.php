<?php

declare(strict_types=1);

namespace App\Dto\Recommendation;

use App\Service\Recommendation\Profile\Model\ProfileSettingsChangeModel;
use App\Service\Recommendation\Profile\Support\ProfileSchedule;
use App\Service\Recommendation\Settings\Support\RecommendationSettingsBounds;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class SaveProfileSettingsRequest
{
    public function __construct(
        #[Assert\Choice(choices: ProfileSchedule::INTERVAL_CHOICES)]
        public ?int $intervalHours,
        #[Assert\Positive]
        public ?int $connectionId,
        #[Assert\Range(
            min: RecommendationSettingsBounds::KEPT_CAP_MINIMUM,
            max: RecommendationSettingsBounds::KEPT_CAP_MAXIMUM,
        )]
        public int $keptCap,
        #[Assert\Range(
            min: RecommendationSettingsBounds::VIEWED_CAP_MINIMUM,
            max: RecommendationSettingsBounds::VIEWED_CAP_MAXIMUM,
        )]
        public int $viewedCap,
    ) {
    }

    public function toChange(): ProfileSettingsChangeModel
    {
        return new ProfileSettingsChangeModel(
            $this->intervalHours,
            $this->connectionId,
            $this->keptCap,
            $this->viewedCap,
        );
    }
}
