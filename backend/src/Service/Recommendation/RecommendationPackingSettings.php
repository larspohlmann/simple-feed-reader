<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * The inputs RecommendationPromptBuilder reads to size a batch: the resolved
 * context window, where it came from, the optional expert batchCount override,
 * and the ceiling on how many candidates one batch may carry. Bundled into one
 * value object so adding batchCount (#321) does not push
 * EffectiveRecommendationSettings's constructor past PHPMD's parameter-count
 * ceiling.
 */
final readonly class RecommendationPackingSettings
{
    /**
     * Hard ceiling on candidates per batch, independent of the token budget.
     *
     * The token budget alone let a large-context model receive 339 candidates
     * in a single batch; ranking that many took over 100 seconds and exceeded
     * the provider request timeout. Ranking time scales with the number of
     * items the model must order, not just with prompt size, so the token
     * budget cannot be the only guard. Raised 40 → 45 in #321 so the default
     * 500-candidate pool packs into 12 batch calls instead of 26. See #308.
     */
    public const int DEFAULT_MAXIMUM_BATCH_SIZE = 45;

    public function __construct(
        public int $contextWindow,
        public string $contextWindowSource,
        public ?int $batchCount,
        public int $maximumBatchSize,
    ) {
    }
}
