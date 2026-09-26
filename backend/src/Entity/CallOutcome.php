<?php

declare(strict_types=1);

namespace App\Entity;

final readonly class CallOutcome
{
    public function __construct(
        public string $verdict,
        public int $wireBytes,
        public \DateTimeImmutable $finishedAt,
        public ?string $finishReason,
    ) {
    }
}
