<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CallVerdict;

final readonly class CallOutcome
{
    public function __construct(
        public CallVerdict $verdict,
        public int $wireBytes,
        public \DateTimeImmutable $finishedAt,
        public ?string $finishReason,
    ) {
    }
}
