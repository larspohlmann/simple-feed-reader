<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Support;

final class RefusalMessage
{
    public static function of(int $status, ?string $reason): string
    {
        return null === $reason
            ? sprintf('That provider refused the request (status %d).', $status)
            : sprintf('That provider refused the request (status %d): %s', $status, $reason);
    }

    private function __construct()
    {
    }
}
