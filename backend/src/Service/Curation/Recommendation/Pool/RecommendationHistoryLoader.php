<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Pool;

use App\Repository\ReadingHistoryRepository;
use App\Service\Curation\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Curation\Recommendation\Pool\Model\RecommendationHistoryModel;
use App\Service\Curation\Recommendation\Settings\Model\EffectiveRecommendationSettingsModel;

/** The reader's weighted history: four capped, newest-first sections. */
final readonly class RecommendationHistoryLoader
{
    public function __construct(
        private ReadingHistoryRepository $history,
        private ArticleLines $articleLines,
    ) {
    }

    public function load(int $userId, EffectiveRecommendationSettingsModel $settings): RecommendationHistoryModel
    {
        $caps = $settings->historyCaps;

        return new RecommendationHistoryModel(
            favorites: $this->favorites($userId, $settings),
            kept: $this->articleLines->loadLines($this->history->kept($userId, $caps->kept)),
            viewed: $this->articleLines->loadLines($this->history->viewed($userId, $caps->viewed)),
            shared: $this->articleLines->loadLines($this->history->shared($userId, $caps->shared)),
        );
    }

    /** @return list<ArticleLineModel> newest first */
    public function favorites(int $userId, EffectiveRecommendationSettingsModel $settings): array
    {
        return $this->articleLines->loadLines($this->history->favorites($userId, $settings->historyCaps->favorites));
    }
}
