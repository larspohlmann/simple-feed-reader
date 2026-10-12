<?php

declare(strict_types=1);

namespace App\Service\Social\Exception;

/** A 429: still an unreachable network to a caller that cannot wait, a schedule to the refresh that can. */
final class SocialNetworkThrottledException extends SocialNetworkUnreachableException
{
    public function __construct(
        string $message,
        public readonly ?int $retryAfterSeconds = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
