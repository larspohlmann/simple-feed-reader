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
     * The address override for the HTTP client's `resolve` option, curl's
     * CURLOPT_RESOLVE multi-address form. Pinning every validated address, not
     * only the first, lets happy-eyeballs fall back across A/AAAA records, so a
     * host whose IPv4 route is dead still connects over IPv6.
     */
    public function pinnedAddresses(): string
    {
        return implode(',', $this->ips);
    }

    /**
     * The `resolve`-option values to try in turn, most-capable first. The first
     * pins every address so happy-eyeballs races both families, rescuing one
     * that's dead at TCP connect — but not one that connects and only then dies:
     * heise's IPv6 route from Strato completes the TCP handshake and resets
     * during TLS, so the client commits to it and never falls back. The later
     * pins narrow to one family each so the caller can re-drive over the family
     * that works. A single-family host yields the one pin only.
     *
     * @return non-empty-list<string>
     */
    public function pinnedAddressAttempts(): array
    {
        // Keys are irrelevant here: the results are only imploded and tested for
        // emptiness, so array_filter's preserved keys need no reindexing.
        $ipv6 = array_filter($this->ips, static fn (string $ip): bool => str_contains($ip, ':'));
        $ipv4 = array_filter($this->ips, static fn (string $ip): bool => !str_contains($ip, ':'));

        if ([] === $ipv6 || [] === $ipv4) {
            return [$this->pinnedAddresses()];
        }

        return [$this->pinnedAddresses(), implode(',', $ipv4), implode(',', $ipv6)];
    }
}
