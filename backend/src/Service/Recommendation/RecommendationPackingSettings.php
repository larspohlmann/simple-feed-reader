<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * The inputs RecommendationPromptBuilder reads to size a batch: the resolved
 * context window, its source, the optional expert batchCount override, and the
 * ceiling on candidates per batch. Bundled into one value object so batchCount
 * (#321) did not push EffectiveRecommendationSettings past PHPMD's
 * parameter-count ceiling.
 */
final readonly class RecommendationPackingSettings
{
    /**
     * Hard ceiling on candidates per batch, independent of the token budget. The budget
     * alone let a large-context model receive 339 candidates in one batch; ranking that
     * many took over 100 seconds and exceeded the provider timeout -- ranking time scales
     * with item count, not just prompt size. Raised 40 → 45 (#321) while a reply carried a
     * prose reason per pick; raised again 45 → 100 in #493, when the batch phase became a
     * score-only filter (`{id, score}`, roughly a fifth the size, far faster). 100 packs
     * the 500-candidate pool into 5 calls (1500 into 15); a trial at 150 packed the answer
     * too tight against a suppressed connection's reduced budget. Finite and well under the
     * 339 that timed out: the model reads and scores every line.
     */
    public const int DEFAULT_MAXIMUM_BATCH_SIZE = 100;

    public function __construct(
        public int $contextWindow,
        public string $contextWindowSource,
        public ?int $batchCount,
        public int $maximumBatchSize,
    ) {
    }
}
