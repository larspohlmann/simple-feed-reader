<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Repository\ReadingHistoryRepository;

/** The reader's weighted history for the recommendation prompt: three capped, newest-first sections. */
final readonly class RecommendationHistoryLoader
{
    public function __construct(private ReadingHistoryRepository $history)
    {
    }

    public function load(int $userId, EffectiveRecommendationSettings $settings): RecommendationHistory
    {
        return new RecommendationHistory(
            favorites: array_map(PromptLine::of(...), $this->history->favorites($userId, $settings->favoritesCap)),
            kept: array_map(PromptLine::of(...), $this->history->kept($userId, $settings->keptCap)),
            viewed: array_map(PromptLine::of(...), $this->history->viewed($userId, $settings->viewedCap)),
        );
    }
}
