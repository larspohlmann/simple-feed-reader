<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Prompt\Model;

/**
 * The whole snapshot pool's size and date span (`Y-m-d`), sent with every randomly sampled batch so the model judges
 * recency against the pool, not its sample. Scoped by the same subscription gate as the candidate lines.
 */
final readonly class CandidatePoolSummaryModel
{
    public function __construct(
        public int $total,
        public string $oldest,
        public string $newest,
    ) {
    }
}
