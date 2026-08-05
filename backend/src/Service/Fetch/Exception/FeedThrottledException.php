<?php

declare(strict_types=1);

namespace App\Service\Fetch\Exception;

/**
 * The site is rationing requests: HTTP 429.
 *
 * Its own kind rather than a plain unreachable feed, because it says something
 * different — the feed is fine, we asked too often. Treating it as a failure
 * costs a healthy feed its schedule: one throttled fetch would set the erroring
 * status and an exponential backoff measured in hours, for a document that
 * would have arrived a minute later. Reddit rations to roughly one request per
 * minute per address, which is what made this worth a type (#290).
 *
 * Still a FeedUnreachableException so the callers that only care that nothing
 * arrived — discovery reporting "the site refused us" — keep working unchanged.
 */
final class FeedThrottledException extends FeedUnreachableException
{
    public function __construct(
        string $message,
        /** How long the site asked us to wait, when it said so at all. */
        public readonly ?int $retryAfterSeconds = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 429, $previous);
    }
}
