<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring\Model;

use App\Service\Ai\Model\ProviderCallReceiptModel;

final readonly class ScoringReplyModel
{
    /** @param array<string, float> $nouls question id => P(yes), only the answers that carry a number */
    public function __construct(
        public string $body,
        public array $nouls,
        public ProviderCallReceiptModel $receipt,
    ) {
    }
}
