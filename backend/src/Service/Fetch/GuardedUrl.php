<?php

declare(strict_types=1);

namespace App\Service\Fetch;

/**
 * A URL that passed the SSRF guard, paired with the IP it resolved to. The
 * caller must pin its connection to that exact IP — re-resolving the hostname
 * would reopen the DNS-rebinding window the guard just closed.
 */
final readonly class GuardedUrl
{
    public function __construct(
        public string $host,
        public string $ip,
    ) {
    }
}
