<?php

declare(strict_types=1);

namespace App\Service\Ai\Completion\Model;

use App\Service\Ai\Exception\ProviderReplyFailureExceptionInterface;
use App\Service\Ai\Exception\RetryableProviderException;

/**
 * One call's result in a concurrent wave: an answer, a spoiled reply or the failure it hit. Returned, not thrown, so
 * the wave has every outcome in hand to bank it or re-run it.
 */
final readonly class CompletionOutcomeModel
{
    private function __construct(
        private string $content,
        private ?\Throwable $cause,
    ) {
    }

    public static function answer(string $content): self
    {
        return new self($content, null);
    }

    public static function failure(\Throwable $cause): self
    {
        return new self('', $cause);
    }

    /**
     * A reply the endpoint delivered and the model spoiled: it failed, but it
     * failed with content, so content() still answers and the caller treats it
     * as the unusable reply it is.
     */
    public static function unusableReply(ProviderReplyFailureExceptionInterface $cause): self
    {
        return new self($cause->partialAnswer(), $cause);
    }

    /**
     * Whether the endpoint failed, the atomic-wave rule's only question, decided here alone. A spoiled reply is not:
     * the address answered, so it must not abort its siblings or count against the transport ceiling.
     */
    public function isFailure(): bool
    {
        return null !== $this->cause && !$this->cause instanceof ProviderReplyFailureExceptionInterface;
    }

    public function content(): string
    {
        if ($this->isFailure()) {
            throw new \LogicException('This outcome is a failure; read cause(), not content().');
        }

        return $this->content;
    }

    /** Whether anything went wrong at all, an endpoint failure or a spoiled reply; isFailure() asks only the first. */
    public function hasCause(): bool
    {
        return null !== $this->cause;
    }

    public function cause(): \Throwable
    {
        if (null === $this->cause) {
            throw new \LogicException('This outcome is an answer; read content(), not cause().');
        }

        return $this->cause;
    }

    public function isRetryable(): bool
    {
        return $this->cause instanceof RetryableProviderException;
    }

    public function retryAfterSeconds(): ?int
    {
        return $this->cause instanceof RetryableProviderException
            ? $this->cause->retryAfterSeconds()
            : null;
    }
}
