<?php

declare(strict_types=1);

namespace App\Service\Fetch\Exception;

/**
 * Nothing usable arrived: no answer at all, or an answer that was not the feed
 * (any non-2xx but 304/410, which have their own meanings).
 *
 * Not final: FeedThrottledException narrows it to "we asked too often", so that
 * every caller which only cares that nothing arrived keeps catching one type.
 */
class FeedUnreachableException extends FetchException
{
    public function __construct(string $message, public readonly ?int $statusCode = null, ?\Throwable $previous = null)
    {
        \assert($statusCode === null || ($statusCode >= 100 && $statusCode <= 599));
        parent::__construct($message, 0, $previous);
    }
}
