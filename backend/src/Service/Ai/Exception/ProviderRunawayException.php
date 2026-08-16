<?php

declare(strict_types=1);

namespace App\Service\Ai\Exception;

/**
 * The model answered, and would not stop.
 *
 * Separate from ProviderUnreachableException because the two need different
 * advice and different handling. Unreachable means the address is wrong or the
 * endpoint is down, and no retry against it can help. A runaway means the
 * endpoint is healthy and the model is repeating itself: a 4B model asked to
 * rank 45 entries emitted invented ids counting down by 100 until `max_tokens`
 * stopped it, 8.2 MB later (#437). Reporting that as "did not answer" sent the
 * reader to look at the network, and made a per-batch model failure fail the
 * whole wave.
 *
 * It carries whatever arrived before the call was cut, so the retry can show
 * the model the start of its own loop instead of asking the same question
 * again unchanged.
 */
final class ProviderRunawayException extends \RuntimeException
{
    public function __construct(string $message, private readonly string $partialAnswer = '')
    {
        parent::__construct($message);
    }

    public function partialAnswer(): string
    {
        return $this->partialAnswer;
    }
}
