<?php

declare(strict_types=1);

namespace App\Service\Ai\Support;

use Symfony\Component\HttpFoundation\Response;

final class RejectingStatus
{
    private const array REFUSING_THE_KEY = [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN];
    private const array NOT_A_VERDICT_ON_THE_REQUEST = [
        ...self::REFUSING_THE_KEY,
        Response::HTTP_REQUEST_TIMEOUT,
        Response::HTTP_TOO_EARLY,
        Response::HTTP_TOO_MANY_REQUESTS,
    ];

    public static function refusesKey(int $status): bool
    {
        return \in_array($status, self::REFUSING_THE_KEY, true);
    }

    public static function matches(int $status): bool
    {
        return $status >= Response::HTTP_BAD_REQUEST
            && $status < Response::HTTP_INTERNAL_SERVER_ERROR
            && !\in_array($status, self::NOT_A_VERDICT_ON_THE_REQUEST, true);
    }

    private function __construct()
    {
    }
}
