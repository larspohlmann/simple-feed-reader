<?php

declare(strict_types=1);

namespace App\Service\Ai\Support;

use App\Entity\AiProviderSettings;

final readonly class ProviderHost
{
    /** Tested with is_string(): a host of '0' survives, a malformed or hostless URL gives null. */
    public static function of(?AiProviderSettings $connection): ?string
    {
        $host = parse_url($connection?->getBaseUrl() ?? '', \PHP_URL_HOST);

        return \is_string($host) ? $host : null;
    }

    private function __construct()
    {
    }
}
