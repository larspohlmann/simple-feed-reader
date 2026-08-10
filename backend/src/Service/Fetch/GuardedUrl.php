<?php

declare(strict_types=1);

namespace App\Service\Fetch;

/**
 * A URL that passed the SSRF guard, paired with every IP it resolved to. The
 * caller must pin its connection to those exact IPs — re-resolving the hostname
 * would reopen the DNS-rebinding window the guard just closed.
 */
final readonly class GuardedUrl
{
    /** @param non-empty-list<string> $ips every resolved address, each validated public */
    public function __construct(
        public string $host,
        public array $ips,
    ) {
    }

    /**
     * The address override for the HTTP client's `resolve` option, in curl's
     * CURLOPT_RESOLVE multi-address form. Pinning every validated address (not
     * only the first) lets the client's happy-eyeballs fall back across the A
     * and AAAA records, so a host whose IPv4 route is dead still connects over
     * IPv6. Each address was validated public, so the rebinding pin is unchanged.
     */
    public function pinnedAddresses(): string
    {
        return implode(',', $this->ips);
    }
}
