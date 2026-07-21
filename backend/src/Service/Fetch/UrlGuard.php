<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use App\Service\Fetch\Exception\SsrfBlockedException;

/**
 * Validates an outbound URL before any connection: scheme allowlist, DNS
 * resolution up front, and rejection of private/reserved target IPs. The
 * resolved IP is returned so the HTTP client can pin the connection to it
 * (closes the DNS-rebinding window).
 */
final class UrlGuard
{
    public function __construct(
        private readonly DnsResolverInterface $dnsResolver,
        private readonly IpValidator $ipValidator,
    ) {
    }

    public function assertSafe(string $url): GuardedUrl
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new SsrfBlockedException(sprintf('Malformed URL "%s"', $url));
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new SsrfBlockedException(sprintf('Credentials in URL "%s"', $url));
        }

        $scheme = strtolower($parts['scheme']);
        if (!\in_array($scheme, ['http', 'https'], true)) {
            throw new SsrfBlockedException(sprintf('Scheme "%s" is not allowed', $scheme));
        }

        $host = strtolower(trim($parts['host'], '[]'));

        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : $this->dnsResolver->resolve($host);

        if ($ips === []) {
            throw new SsrfBlockedException(sprintf('DNS resolution failed for "%s"', $host));
        }

        foreach ($ips as $ip) {
            if (!$this->ipValidator->isPublic($ip)) {
                throw new SsrfBlockedException(sprintf('Host "%s" resolves to non-public address %s', $host, $ip));
            }
        }

        // Every record was validated above, so pinning the first is safe.
        return new GuardedUrl($host, $ips[0]);
    }
}
