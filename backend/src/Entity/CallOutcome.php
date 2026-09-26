<?php

declare(strict_types=1);

namespace App\Entity;

/** How a provider call settled: the parser's verdict, what it cost on the wire, when, and why the provider stopped. */
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
