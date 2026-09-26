<?php

declare(strict_types=1);

namespace App\Repository;

/** What a settled provider call writes onto its run-log row, whatever the verdict. */
final readonly class CallSettlement
{
    public function __construct(
        public int $logId,
        public string $verdict,
        public int $wireBytes,
        public \DateTimeImmutable $finishedAt,
        public ?string $finishReason,
    ) {
    }
}
