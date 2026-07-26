<?php

declare(strict_types=1);

namespace App\Service\Fetch\Exception;

final class ResponseTooLargeException extends FetchException
{
    /**
     * The one size a feed response may reach. Two guards measure it — wire bytes
     * as they arrive, and the buffered body afterwards — and they only bound the
     * same memory while they quote the same number, so the number lives here,
     * beside the failure it produces, instead of once per fetcher.
     */
    public const int MAX_BYTES = 5_000_000;

    /**
     * The HTTP client wraps exceptions thrown inside on_progress; unwrap and
     * rethrow this one so callers see the real cause rather than a generic
     * transport failure.
     */
    public static function rethrowIfWrapped(?\Throwable $e): void
    {
        while (null !== $e) {
            if ($e instanceof self) {
                throw $e;
            }
            $e = $e->getPrevious();
        }
    }
}
