<?php

declare(strict_types=1);

namespace App\Service\Fetch\Exception;

/**
 * The site is rationing requests: HTTP 429.
 *
 * Its own kind, and deliberately NOT a FeedUnreachableException: it says
 * something different. The feed is fine and we asked too often, so the answer
 * is a schedule, not a diagnosis. Every caller that treats it as an ordinary
 * failure costs a healthy feed its place in the rotation — the erroring status
 * and an exponential backoff measured in hours, for a document that would have
 * arrived a minute later. Reddit rations to roughly one request per minute per
 * address, which is what made this worth a type (#290).
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
