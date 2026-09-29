<?php

declare(strict_types=1);

namespace App\Service\Fetch\Exception;

final class ResponseTooLargeException extends FetchException
{
    /**
     * The one feed-response size limit. The wire-byte guard and the buffered-body guard bound the same memory only
     * while they quote the same number.
     */
    private const int MAX_BYTES = 5_000_000;

    /** @throws self when $observedBytes exceeds the limit */
    public static function throwIfExceeded(int $observedBytes, ?string $url = null): void
    {
        if ($observedBytes <= self::MAX_BYTES) {
            return;
        }

        throw new self(null === $url
            ? sprintf('response exceeds %d bytes', self::MAX_BYTES)
            : sprintf('%s: response exceeds %d bytes', $url, self::MAX_BYTES));
    }
}
