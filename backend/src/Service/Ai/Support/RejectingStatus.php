<?php

declare(strict_types=1);

namespace App\Service\Ai\Support;

final class RejectingStatus
{
    private const array REFUSING_THE_KEY = [401, 403];
    private const array NOT_A_VERDICT_ON_THE_REQUEST = [...self::REFUSING_THE_KEY, 408, 425, 429];

    public static function refusesKey(int $status): bool
    {
        return \in_array($status, self::REFUSING_THE_KEY, true);
    }

    public static function matches(int $status): bool
    {
        return $status >= 400 && $status < 500 && !\in_array($status, self::NOT_A_VERDICT_ON_THE_REQUEST, true);
    }

    private function __construct()
    {
    }
}
