<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Service\Ai\Exception\ProviderReplyFailure;

/**
 * The result of one call in a concurrent wave: a decoded answer or the
 * transport failure it hit. completeMany() returns one per call, not a throw,
 * so one failed call never discards a sibling's answer — the atomic-wave rule
 * needs every outcome in hand to bank the wave or re-run it (#344).
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

    /**
     * A reply the endpoint delivered and the model spoiled: it failed, but it
     * failed with content, so content() still answers and the caller treats it
     * as the unusable reply it is.
     */
    public static function unusableReply(ProviderReplyFailure $cause): self
    {
        return new self($cause->partialAnswer(), $cause);
    }

    /**
     * Whether the *endpoint* failed — the only question the atomic-wave rule
     * asks. A reply failure is deliberately not one: the address answered, so a
     * badly answering model must not abort its siblings or count against the
     * transport ceiling (#437).
     *
     * The one place this distinction is drawn. It used to be `instanceof` in
     * three classes — the wave negating it, two sites matching it — with
     * nothing holding them in step.
     */
    public function isFailure(): bool
    {
        return null !== $this->cause && !$this->cause instanceof ProviderReplyFailure;
    }

    public function content(): string
    {
        if ($this->isFailure()) {
            throw new \LogicException('This outcome is a failure; read cause(), not content().');
        }

        return $this->content;
    }

    /**
     * Whether anything went wrong with this call at all — an endpoint failure
     * or a spoiled reply. Distinct from isFailure(), which asks only about the
     * endpoint: a caller reporting what happened wants both, a caller deciding
     * whether to abort the wave wants only the first.
     */
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
}
