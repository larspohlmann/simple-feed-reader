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
    private const int MAX_BYTES = 5_000_000;

    /**
     * Both size guards call this at their own checkpoint — wire bytes as they
     * arrive, before a URL is even resolved, and the buffered body once the
     * response is complete. Returning null when $observedBytes is within the
     * limit lets each guard stay a plain `if`, while the limit itself and the
     * two message shapes it produces live only here.
     */
    public static function ifExceeded(int $observedBytes, ?string $url = null): ?self
    {
        if ($observedBytes <= self::MAX_BYTES) {
            return null;
        }

        return new self(null === $url
            ? sprintf('response exceeds %d bytes', self::MAX_BYTES)
            : sprintf('%s: response exceeds %d bytes', $url, self::MAX_BYTES));
    }
}
