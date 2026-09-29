<?php

declare(strict_types=1);

namespace App\Service\Fetch\Exception;

/**
 * HTTP 429: the feed is fine and we asked too often, so the answer is a schedule, not a diagnosis. It is not a
 * FeedUnreachableException: treated as one, it costs a healthy feed the erroring status and an hours-long backoff.
 */
final class FeedThrottledException extends FetchException
{
    public function __construct(
        string $message,
        /** How long the site asked us to wait, when it said so at all. */
        public readonly ?int $retryAfterSeconds = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
