<?php

declare(strict_types=1);

namespace App\Service\RateLimit\Exception;

final class RateLimitedException extends \RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct('Too many attempts. Try again later.');
    }
}
