<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Prompt\Model;

/**
 * The weighted reading history in its three prompt sections. An entry appears in one only: favorites beat kept, and
 * kept beats viewed.
 */
final readonly class RecommendationHistoryModel
{
    /**
     * @param list<PromptLineModel> $favorites
     * @param list<PromptLineModel> $kept
     * @param list<PromptLineModel> $viewed
     */
    public function __construct(
        public array $favorites,
        public array $kept,
        public array $viewed,
    ) {
    }
}
