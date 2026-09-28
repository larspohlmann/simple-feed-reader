<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest\Model;

/** The outcome of one SendDueDigests sweep, for the worker/tick log line. */
final readonly class DigestSweepReportModel
{
    public function __construct(
        public int $considered,
        public int $sent,
        public int $skippedEmpty,
    ) {
    }

    /** @return array{considered: int, sent: int, skippedEmpty: int} */
    public function toLogContext(): array
    {
        return ['considered' => $this->considered, 'sent' => $this->sent, 'skippedEmpty' => $this->skippedEmpty];
    }
}
