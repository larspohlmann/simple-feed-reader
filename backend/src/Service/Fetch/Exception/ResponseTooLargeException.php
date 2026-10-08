<?php

declare(strict_types=1);

namespace App\Service\Fetch\Exception;

use App\Service\Fetch\Model\ResponseSizeLimit;

final class ResponseTooLargeException extends FetchException
{
    /** @throws self when $observedBytes exceeds the limit */
    public static function throwIfExceeded(ResponseSizeLimit $limit, int $observedBytes, ?string $url = null): void
    {
        if ($observedBytes <= $limit->bytes()) {
            return;
        }

        $reason = sprintf('the response is larger than the %d MB limit', $limit->value);

        throw new self(null === $url ? $reason : sprintf('%s: %s', $url, $reason));
    }
}
