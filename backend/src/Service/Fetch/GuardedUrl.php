<?php

declare(strict_types=1);

namespace App\Service\Fetch;

final readonly class GuardedUrl
{
    public function __construct(
        public string $url,
        public string $scheme,
        public string $host,
        public int $port,
        public string $ip,
    ) {
    }
}
