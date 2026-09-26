<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CallOutcome;

/** What a settled provider call writes onto its run-log row, whatever the verdict. */
final readonly class CallSettlement
{
    public function __construct(
        public int $logId,
        public CallOutcome $outcome,
    ) {
    }
}
