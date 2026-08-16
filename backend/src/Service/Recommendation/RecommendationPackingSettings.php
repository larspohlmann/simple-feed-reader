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

    /**
     * What a connection the account marked as slow gets instead.
     *
     * The ceiling was one number for every endpoint until #437, where a 4B
     * local model given 45 entries answered eight batches correctly and then
     * fell into a repetition loop on the ninth — inventing ids counting down
     * by 100 until `max_tokens` stopped it. A shorter list is easier for a
     * small model to hold in order, and it bounds what one runaway can cost:
     * the reserve, and so the ceiling a loop runs to, scales with the batch.
     *
     * The trade is real and is why this is not simply lower for everyone. The
     * history sections are re-sent with every batch, so smaller batches mean
     * more calls and more prompt tokens over the run. It buys reliability on
     * the endpoints that need it and is not imposed on the ones that do not.
     */
    public const int SLOW_MODEL_MAXIMUM_BATCH_SIZE = 30;

    public function __construct(
        public int $contextWindow,
        public string $contextWindowSource,
        public ?int $batchCount,
        public int $maximumBatchSize,
    ) {
    }
}
