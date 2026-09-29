<?php

declare(strict_types=1);

namespace App\Service\Ai\Exception;

/**
 * The endpoint answered and the answer is the problem: unlike an endpoint failure, which aborts the wave and counts
 * toward the transport ceiling, it costs only its own call a corrective retry. A new kind picks its side here.
 */
interface ProviderReplyFailureExceptionInterface extends \Throwable
{
    /** Whatever arrived before the reply was rejected; the retry quotes it back. */
    public function partialAnswer(): string;
}
