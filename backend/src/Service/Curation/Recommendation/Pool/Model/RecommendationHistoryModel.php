<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Pool\Model;

/**
 * The weighted reading history in four sections. An entry appears in one only: favorites beat shared, shared
 * beats kept, and kept beats viewed.
 */
final readonly class RecommendationHistoryModel
{
    /**
     * @param list<ArticleLineModel> $favorites
     * @param list<ArticleLineModel> $kept
     * @param list<ArticleLineModel> $viewed
     * @param list<ArticleLineModel> $shared
     */
    public function __construct(
        public array $favorites,
        public array $kept,
        public array $viewed,
        public array $shared = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->favorites && [] === $this->kept && [] === $this->viewed && [] === $this->shared;
    }
}
