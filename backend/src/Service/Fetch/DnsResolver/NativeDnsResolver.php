<?php

declare(strict_types=1);

namespace App\Service\Fetch\DnsResolver;

/**
 * SECURITY: stay on dns_get_record(), which does no inet_aton parsing, so 0177.0.0.1, 2130706433 or 127.1 resolve to
 * nothing. gethostbyname() or getaddrinfo() would decode them to loopback behind UrlGuard's back: a live SSRF.
 */
final readonly class NativeDnsResolver implements DnsResolverInterface
{
    public function resolve(string $hostname): array
    {
        $records = @dns_get_record($hostname, DNS_A | DNS_AAAA);
        if ($records === false) {
            return [];
        }

        $ips = [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (\is_string($ip) && $ip !== '') {
                $ips[] = $ip;
            }
        }

        return array_values(array_unique($ips));
    }
}
