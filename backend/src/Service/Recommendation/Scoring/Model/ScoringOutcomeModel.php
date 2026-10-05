<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring\Model;

use App\Service\Ai\Exception\RetryableProviderException;
use App\Service\Recommendation\Run\BatchCallOutcome\BatchCallOutcomeInterface;

/** One request's result in a wave: the reply, or the failure it hit. Returned, not thrown, so siblings keep theirs. */
final readonly class ScoringOutcomeModel implements BatchCallOutcomeInterface
{
    private function __construct(
        private ?ScoringReplyModel $reply,
        private ?\RuntimeException $cause,
    ) {
    }

    public static function answered(ScoringReplyModel $reply): self
    {
        return new self($reply, null);
    }

    public static function failed(\RuntimeException $cause): self
    {
        return new self(null, $cause);
    }

    public function isFailure(): bool
    {
        return null !== $this->cause;
    }

    public function hasCause(): bool
    {
        return $this->isFailure();
    }

    public function reply(): ScoringReplyModel
    {
        return $this->reply ?? throw new \LogicException('This outcome is a failure; read cause(), not reply().');
    }

    public function cause(): \RuntimeException
    {
        return $this->cause ?? throw new \LogicException('This outcome is a reply; read reply(), not cause().');
    }

    public function isRetryable(): bool
    {
        return $this->cause instanceof RetryableProviderException;
    }

    public function retryAfterSeconds(): ?int
    {
        return $this->cause instanceof RetryableProviderException ? $this->cause->retryAfterSeconds() : null;
    }
}
