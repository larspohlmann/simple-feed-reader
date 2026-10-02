<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Ai\RateLimitedOutcome\RateLimitedOutcomeInterface;

/** An outcome that is no chat completion: proves the rate-limit loop knows only the interface. */
final readonly class ScriptedRateLimitedOutcome implements RateLimitedOutcomeInterface
{
    private function __construct(
        public string $label,
        private bool $limited,
        private ?int $retryAfterSeconds,
    ) {
    }

    public static function answered(string $label): self
    {
        return new self($label, false, null);
    }

    public static function limited(string $label, ?int $retryAfterSeconds): self
    {
        return new self($label, true, $retryAfterSeconds);
    }

    public function isRetryable(): bool
    {
        return $this->limited;
    }

    public function retryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }
}
