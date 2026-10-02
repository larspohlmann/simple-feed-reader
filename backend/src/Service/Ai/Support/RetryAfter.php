<?php

declare(strict_types=1);

namespace App\Service\Ai\Support;

use App\Service\Fetch\Support\ResponseHeader;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class RetryAfter
{
    /**
     * Integer seconds only: an HTTP-date form is left to the caller's backoff, as turning a date into a wait needs a
     * clock, and the standard rate-limit form is a seconds count anyway.
     */
    public static function secondsIn(ResponseInterface $response): ?int
    {
        $header = ResponseHeader::first($response, 'retry-after');

        return null !== $header && ctype_digit($header) ? (int) $header : null;
    }

    private function __construct()
    {
    }
}
