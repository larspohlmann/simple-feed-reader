<?php

declare(strict_types=1);

namespace App\Service\Fetch\Exception;

abstract class FetchException extends \RuntimeException
{
    /**
     * The HTTP client wraps exceptions thrown inside on_progress, so a
     * ResponseTooLargeException raised there arrives back here buried inside
     * $previous. Unwrapping it at every catch site is exactly the kind of step
     * a future edit forgets; centralising it here makes forgetting impossible —
     * an empty catch block is the only way left to skip it, and no reviewer
     * misses that.
     */
    public static function from(string $url, \Throwable $previous): self
    {
        for ($e = $previous; null !== $e; $e = $e->getPrevious()) {
            if ($e instanceof ResponseTooLargeException) {
                return $e;
            }
        }

        return new FeedUnreachableException(sprintf('%s: %s', $url, $previous->getMessage()), previous: $previous);
    }
}
