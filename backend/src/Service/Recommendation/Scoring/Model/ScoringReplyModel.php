<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring\Model;

use App\Service\Ai\Model\ProviderCallReceiptModel;

final readonly class ScoringReplyModel
{
    /** @param array<int, float> $scores entry id => the model's value for it, only the answers that carry a number */
    public function __construct(
        public string $body,
        public array $scores,
        public ProviderCallReceiptModel $receipt,
    ) {
    }
}
