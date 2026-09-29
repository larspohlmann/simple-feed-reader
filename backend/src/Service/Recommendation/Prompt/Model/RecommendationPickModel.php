<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Prompt\Model;

/**
 * One scored entry with the model's reason. Only a blank reason is rejected, as a bad one is not worth losing the
 * pick over; a pick without a numeric score cannot be ranked, so the parser drops it.
 */
final readonly class RecommendationPickModel
{
    public function __construct(
        public int $entryId,
        public int $score,
        public string $reason,
    ) {
    }
}
