<?php

declare(strict_types=1);

namespace App\Service\SelfUpdate\Model;

final readonly class UpdateRunModel
{
    public function __construct(
        public string $requestId,
        public SelfUpdatePhase $phase,
        public ?\DateTimeImmutable $startedAt,
        public ?\DateTimeImmutable $finishedAt,
        public ?int $exitCode,
    ) {
    }
}
