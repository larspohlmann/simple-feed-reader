<?php

declare(strict_types=1);

namespace App\Service\Outbound\Fetch\Exception;

use App\Service\Outbound\Fetch\Support\ProxyHandshakeFailure;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;

abstract class FetchException extends \RuntimeException
{
    public function isTimeout(): bool
    {
        return $this->hasCauseMatching(
            static fn (\Throwable $cause): bool => $cause instanceof TimeoutExceptionInterface,
        );
    }

    public function isPossiblyDelivered(): bool
    {
        return $this->hasCauseMatching(
            static fn (\Throwable $cause): bool => $cause instanceof RequestMayHaveLeftException,
        );
    }

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

    /** @param \Closure(\Throwable): bool $matches */
    private function hasCauseMatching(\Closure $matches): bool
    {
        for ($cause = $this->getPrevious(); null !== $cause; $cause = $cause->getPrevious()) {
            if ($matches($cause)) {
                return true;
            }
        }

        return false;
    }
}
