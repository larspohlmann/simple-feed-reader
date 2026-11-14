<?php

declare(strict_types=1);

namespace App\Service\Fetch\Model;

/**
 * A URL that passed the SSRF guard, paired with every IP it resolved to. The
 * caller must pin its connection to those exact IPs — re-resolving the hostname
 * would reopen the DNS-rebinding window the guard just closed.
 */
final readonly class GuardedUrlModel
{
    /** @param non-empty-list<string> $ips every resolved address, each validated public */
    public function __construct(
        public string $host,
        public array $ips,
    ) {
    }

    /**
     * The `resolve` option in curl's CURLOPT_RESOLVE multi-address form. Pinning every validated address lets happy
     * eyeballs fall back across A and AAAA records when one family's route is dead.
     */
    public function pinnedAddresses(): string
    {
        return implode(',', $this->ips);
    }

    /**
     * The `resolve` values to try in turn: every address first, so happy eyeballs races both families, then one family
     * each, so the caller can re-drive past a family that connects and then resets. A single-family host gets one.
     *
     * @return non-empty-list<string>
     */
    public function pinnedAddressAttempts(): array
    {
        $ipv6 = array_filter($this->ips, static fn (string $ip): bool => str_contains($ip, ':'));
        $ipv4 = array_filter($this->ips, static fn (string $ip): bool => !str_contains($ip, ':'));

        if ([] === $ipv6 || [] === $ipv4) {
            return [$this->pinnedAddresses()];
        }

        return [$this->pinnedAddresses(), implode(',', $ipv4), implode(',', $ipv6)];
    }
}
