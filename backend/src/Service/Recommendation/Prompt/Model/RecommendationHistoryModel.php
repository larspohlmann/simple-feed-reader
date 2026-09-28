<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Prompt\Model;

/**
 * The reader's weighted reading history, already split into its three prompt
 * sections. Each entry appears in exactly one: favorites beat kept, kept
 * beats viewed, so an entry that is both favorited and viewed only shows up
 * among the favorites.
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
