<?php

declare(strict_types=1);

namespace App\Service\Outbound\Fetch\Exception;

use App\Service\Outbound\Fetch\Model\ResponseSizeLimit;

final class ResponseTooLargeException extends FetchException
{
    /** @throws self when $observedBytes exceeds the limit */
    public static function throwIfExceeded(ResponseSizeLimit $limit, int $observedBytes, ?string $url = null): void
    {
        if ($observedBytes <= $limit->bytes()) {
            return;
        }

        $reason = sprintf('the response is larger than the %s limit', $limit->label());

        throw new self(null === $url ? $reason : sprintf('%s: %s', $url, $reason));
    }
}
