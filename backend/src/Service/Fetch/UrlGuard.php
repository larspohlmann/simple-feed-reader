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
final readonly class UrlGuard
{
    public function __construct(
        private DnsResolverInterface $dnsResolver,
        private IpValidator $ipValidator,
    ) {
    }

    public function assertSafe(string $url): GuardedUrl
    {
        $host = $this->parseAllowedHost($url);
        $ips = $this->resolveToPublicIps($host);

        // Every record was validated above, so pinning them all is safe — and
        // pinning the whole set, not just the first, keeps the client's
        // cross-family fallback alive when one address is unroutable.
        return new GuardedUrl($host, $ips);
    }

    /**
     * Rejects malformed URLs, embedded credentials and non-HTTP schemes, then
     * returns the normalised host (lowercased, IPv6 brackets stripped).
     */
    private function parseAllowedHost(string $url): string
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

        return strtolower(trim($parts['host'], '[]'));
    }

    /**
     * Resolves the host to its target addresses and rejects the whole URL if
     * any record is a private/reserved address. An IP literal skips DNS.
     *
     * @return non-empty-list<string>
     */
    private function resolveToPublicIps(string $host): array
    {
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

        return $ips;
    }
}
