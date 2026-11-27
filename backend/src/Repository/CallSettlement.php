<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CallOutcome;
use App\Entity\CallReceipt;

/** What a settled provider call writes onto its run-log row, whatever the verdict; a streamed call has no receipt. */
final readonly class CallSettlement
{
    public function __construct(
        public int $logId,
        public CallOutcome $outcome,
        public ?CallReceipt $receipt = null,
    ) {
    }
}
