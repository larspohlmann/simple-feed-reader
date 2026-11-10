<?php

declare(strict_types=1);

namespace App\Service\Fetch\Support;

/**
 * Options for the hot fetch paths: connections, TLS sessions and DNS kept per worker process, and a connect phase
 * capped well under the read timeout so a host that never answers fails fast (#2155).
 */
final class PersistentConnection
{
    private const float CONNECT_SECONDS = 5.0;

    /** @return array{max_connect_duration: float, extra: array{use_persistent_connections: true}} */
    public static function options(): array
    {
        return [
            'max_connect_duration' => self::CONNECT_SECONDS,
            'extra' => ['use_persistent_connections' => true],
        ];
    }

    private function __construct()
    {
    }
}
