<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Recommendation\Run\BatchCallOutcome\BatchCallOutcomeInterface;

/** A batch call's outcome as a test scripts it: a reply, a spoiled reply that names its cause, or an endpoint failure. */
final readonly class ScriptedBatchOutcome implements BatchCallOutcomeInterface
{
    private function __construct(
        public string $reply,
        private ?\RuntimeException $cause,
        private bool $endpointFailed,
    ) {
    }

    public static function reply(string $reply): self
    {
        return new self($reply, null, false);
    }

    public static function spoiled(string $reply, \RuntimeException $cause): self
    {
        return new self($reply, $cause, false);
    }

    public static function failure(\RuntimeException $cause): self
    {
        return new self('', $cause, true);
    }

    public function isFailure(): bool
    {
        return $this->endpointFailed;
    }

    public function hasCause(): bool
    {
        return null !== $this->cause;
    }

    public function cause(): \RuntimeException
    {
        return $this->cause ?? throw new \LogicException('This outcome is a reply; it has no cause.');
    }

    public function isRetryable(): bool
    {
        return false;
    }

    public function retryAfterSeconds(): ?int
    {
        return null;
    }
}
