<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Feed\Model;

final readonly class RunCallCountersModel
{
    public function __construct(
        public int $attempts,
        public int $maxAttempts,
        public int $transportFailures,
        public int $maxTransportFailures,
    ) {
    }
}
