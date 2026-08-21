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
     * budget cannot be the only guard. It was 40 → 45 (#321) while a batch
     * reply carried a prose reason per pick.
     *
     * Raised 45 → 150 in #493: the batch phase is now a score-only coarse
     * filter, so a reply is `{id, score}` per pick — roughly a fifth of the
     * reason-bearing reply the old cap was measured against, and generated far
     * faster, which relaxes both the reply-size and the generation-time
     * pressure that held the cap at 45. 150 packs the default 500-candidate
     * pool into 4 batch calls (and a 1500 pool into 10) instead of tens of
     * them. Still finite and well under the 339 that timed out: the model
     * must read and score every line in the batch, so the ceiling stays.
     */
    public const int DEFAULT_MAXIMUM_BATCH_SIZE = 150;

    public function __construct(
        public int $contextWindow,
        public string $contextWindowSource,
        public ?int $batchCount,
        public int $maximumBatchSize,
    ) {
    }
}
