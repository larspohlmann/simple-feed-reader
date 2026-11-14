<?php

declare(strict_types=1);

namespace App\Service\Ai\Exception;

/**
 * A status the run may wait out and retry (429, 502, 503, 504), with the parsed `Retry-After` when sent. Apart from
 * ProviderUnreachableException so a rate limit throttles and retries while a dead address fails fast.
 */
final class RetryableProviderException extends \RuntimeException
{
    public function __construct(
        private readonly int $status,
        private readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct(sprintf('That provider answered with status %d.', $status));
    }

    public function status(): int
    {
        return $this->status;
    }

    public function retryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }
}
