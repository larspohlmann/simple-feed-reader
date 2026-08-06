<?php

declare(strict_types=1);

namespace App\Service\Fetch\Exception;

/**
 * Nothing usable arrived: no answer at all, or an answer that was not the feed
 * (any non-2xx but 304, 410 and 429, which have their own meanings).
 */
final class FeedUnreachableException extends FetchException
{
    public function __construct(string $message, public readonly ?int $statusCode = null, ?\Throwable $previous = null)
    {
        \assert($statusCode === null || ($statusCode >= 100 && $statusCode <= 599));
        parent::__construct($message, 0, $previous);
    }
}
