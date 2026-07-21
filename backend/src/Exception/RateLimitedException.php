<?php

declare(strict_types=1);

namespace App\Exception;

final class RateLimitedException extends ApiException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct(
            'rate_limited',
            429,
            'Too many requests',
            'Too many attempts. Try again later.',
        );
    }
}
