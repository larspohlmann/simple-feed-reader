<?php

declare(strict_types=1);

namespace App\Service\Fetch\Exception;

use App\Service\Fetch\Support\ProxyHandshakeFailure;

abstract class FetchException extends \RuntimeException
{
    /**
     * The HTTP client wraps an exception thrown inside on_progress, so a ResponseTooLargeException raised there comes
     * back buried in $previous. Every catch site goes through here, so none can forget to unwrap it.
     */
    public static function from(string $url, \Throwable $previous): self
    {
        for ($cause = $previous; null !== $cause; $cause = $cause->getPrevious()) {
            if ($cause instanceof ResponseTooLargeException) {
                return $cause;
            }
        }

        // A proxied sweep can fail every feed on the same handshake, so the
        // reason is translated here rather than once at the top: the report the
        // admin reads is built from these messages.
        return new FeedUnreachableException(
            sprintf('%s: %s', $url, ProxyHandshakeFailure::explain($previous->getMessage())),
            previous: $previous,
        );
    }
}
