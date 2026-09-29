<?php

declare(strict_types=1);

namespace App\Service\Ai\Exception;

/**
 * The model answered and would not stop. Apart from ProviderUnreachableException because the endpoint is healthy:
 * the retry quotes the partial answer back to break the loop, and one batch's runaway must not fail the wave.
 */
final class ProviderRunawayException extends \RuntimeException implements ProviderReplyFailureExceptionInterface
{
    /** $partialAnswer arrives clipped: unclipped, it would be stored in `last_invalid_reply` and re-read every tick. */
    public function __construct(string $message, private readonly string $partialAnswer)
    {
        parent::__construct($message);
    }

    public function partialAnswer(): string
    {
        return $this->partialAnswer;
    }
}
