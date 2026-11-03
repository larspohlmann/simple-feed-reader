<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Pool;

use App\Repository\ReadingHistoryRepository;
use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Pool\Model\RecommendationHistoryModel;
use App\Service\Recommendation\Settings\Model\EffectiveRecommendationSettingsModel;

/** The reader's weighted history: four capped, newest-first sections. */
final readonly class RecommendationHistoryLoader
{
    public function __construct(private ReadingHistoryRepository $history)
    {
    }

    public function load(int $userId, EffectiveRecommendationSettingsModel $settings): RecommendationHistoryModel
    {
        $caps = $settings->historyCaps;

        return new RecommendationHistoryModel(
            favorites: $this->favorites($userId, $settings),
            kept: array_map(ArticleLineModel::of(...), $this->history->kept($userId, $caps->kept)),
            viewed: array_map(ArticleLineModel::of(...), $this->history->viewed($userId, $caps->viewed)),
            shared: array_map(ArticleLineModel::of(...), $this->history->shared($userId, $caps->shared)),
        );
    }

    /** @return list<ArticleLineModel> newest first */
    public function favorites(int $userId, EffectiveRecommendationSettingsModel $settings): array
    {
        return array_map(
            ArticleLineModel::of(...),
            $this->history->favorites($userId, $settings->historyCaps->favorites),
        );
    }
}
