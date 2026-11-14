<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Settings\Model;

use App\Enum\RecommendationBatchSize;

/** What sizes a batch: the context window and its source, the reader's batch size, and the connection's ceiling. */
final readonly class RecommendationPackingSettingsModel
{
    /**
     * Ranking time scales with item count, not prompt size: 339 candidates in one batch ran past the provider timeout
     * (#321). 100 fits the score-only batch reply; a trial at 150 packed the answer too tight for suppressed reasoning.
     */
    public const int DEFAULT_MAXIMUM_BATCH_SIZE = 100;

    public function __construct(
        public int $contextWindow,
        public string $contextWindowSource,
        public RecommendationBatchSize $batchSize,
        public int $maximumBatchSize,
    ) {
    }
}
