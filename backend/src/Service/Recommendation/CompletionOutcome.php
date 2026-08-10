<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * The result of one call in a concurrent wave: either a decoded answer or the
 * transport failure that call hit. completeMany() returns one per call rather
 * than throwing, so a single failed call never discards a sibling's answer —
 * the atomic-wave rule needs every outcome of a wave in hand to decide whether
 * to bank the wave or re-run it (#344).
 */
final readonly class CompletionOutcome
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

    public function isFailure(): bool
    {
        return null !== $this->cause;
    }

    public function content(): string
    {
        if (null !== $this->cause) {
            throw new \LogicException('This outcome is a failure; read cause(), not content().');
        }

        return $this->content;
    }

    public function cause(): \Throwable
    {
        if (null === $this->cause) {
            throw new \LogicException('This outcome is an answer; read content(), not cause().');
        }

        return $this->cause;
    }
}
