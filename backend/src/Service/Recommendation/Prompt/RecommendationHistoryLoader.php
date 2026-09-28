<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Prompt;

use App\Repository\ReadingHistoryRepository;
use App\Service\Recommendation\Prompt\Model\PromptLineModel;
use App\Service\Recommendation\Prompt\Model\RecommendationHistoryModel;
use App\Service\Recommendation\Settings\Model\EffectiveRecommendationSettingsModel;

/** The reader's weighted history for the recommendation prompt: three capped, newest-first sections. */
final readonly class RecommendationHistoryLoader
{
    public function __construct(private ReadingHistoryRepository $history)
    {
    }

    public function load(int $userId, EffectiveRecommendationSettingsModel $settings): RecommendationHistoryModel
    {
        return new RecommendationHistoryModel(
            favorites: array_map(PromptLineModel::of(...), $this->history->favorites($userId, $settings->favoritesCap)),
            kept: array_map(PromptLineModel::of(...), $this->history->kept($userId, $settings->keptCap)),
            viewed: array_map(PromptLineModel::of(...), $this->history->viewed($userId, $settings->viewedCap)),
        );
    }
}
