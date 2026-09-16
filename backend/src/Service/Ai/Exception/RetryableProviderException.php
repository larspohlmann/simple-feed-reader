<?php

declare(strict_types=1);

namespace App\Service\Ai\Exception;

/**
 * A provider status the run may wait out and retry: 429, 502, 503, 504. Carries
 * the parsed `Retry-After` when the response supplied one. Distinct from
 * ProviderUnreachableException so the advancer can tell a rate limit — which
 * throttles and retries — from a dead address, which fails fast.
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
