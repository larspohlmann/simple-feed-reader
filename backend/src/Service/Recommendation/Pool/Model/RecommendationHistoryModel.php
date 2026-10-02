<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Pool\Model;

/**
 * The weighted reading history in three sections. An entry appears in one only: favorites beat kept, and
 * kept beats viewed.
 */
final readonly class RecommendationHistoryModel
{
    /**
     * @param list<ArticleLineModel> $favorites
     * @param list<ArticleLineModel> $kept
     * @param list<ArticleLineModel> $viewed
     */
    public function __construct(
        public array $favorites,
        public array $kept,
        public array $viewed,
    ) {
    }
}
