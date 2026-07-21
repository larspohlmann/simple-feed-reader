<?php

declare(strict_types=1);

namespace App\Service\Fetch;

/**
 * SECURITY: this must stay on dns_get_record, which performs real DNS queries
 * and does not apply legacy inet_aton parsing. Alternate IPv4 encodings
 * (0177.0.0.1, 2130706433, 0x7f.0.0.1, 127.1) therefore resolve to nothing and
 * are rejected upstream as unresolvable. Switching to gethostbyname() or
 * getaddrinfo() would turn every one of them into a live loopback SSRF, since
 * those APIs do apply that parsing and UrlGuard never sees the decoded address.
 */
final class NativeDnsResolver implements DnsResolverInterface
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
