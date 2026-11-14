<?php

declare(strict_types=1);

namespace App\Service\Outbound\Fetch;

use App\Service\Outbound\Fetch\DnsResolver\DnsResolverInterface;
use App\Service\Outbound\Fetch\Exception\SsrfBlockedException;
use App\Service\Outbound\Fetch\Exception\UnresolvableHostException;
use App\Service\Outbound\Fetch\Model\GuardedUrlModel;

/**
 * Validates an outbound URL before any connection: scheme allowlist, DNS resolved up front, no private or reserved
 * target. It returns the resolved IPs for the client to pin, which closes the DNS-rebinding window.
 */
final readonly class UrlGuard
{
    private const int IDNA_FLAGS = \IDNA_DEFAULT | \IDNA_USE_STD3_RULES | \IDNA_CHECK_BIDI | \IDNA_CHECK_CONTEXTJ
        | \IDNA_NONTRANSITIONAL_TO_ASCII;

    public function __construct(
        private DnsResolverInterface $dnsResolver,
        private IpValidator $ipValidator,
    ) {
    }

    public function assertSafe(string $url): GuardedUrlModel
    {
        $host = $this->parseAllowedHost($url);
        $ips = $this->resolveToPublicIps($host);

        // Every record was validated above, so pinning them all is safe — and
        // pinning the whole set, not just the first, keeps the client's
        // cross-family fallback alive when one address is unroutable.
        return new GuardedUrlModel($host, $ips);
    }

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

        return $this->asCurlHostName(trim($parts['host'], '[]'));
    }

    private function asCurlHostName(string $host): string
    {
        return idn_to_ascii($host, self::IDNA_FLAGS, \INTL_IDNA_VARIANT_UTS46) ?: strtolower($host);
    }

    /**
     * Rejects the whole URL when any record is private or reserved; an IP literal skips DNS.
     *
     * @return non-empty-list<string>
     */
    private function resolveToPublicIps(string $host): array
    {
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : $this->dnsResolver->resolve($host);

        if ($ips === []) {
            throw new UnresolvableHostException(sprintf('DNS resolution failed for "%s"', $host));
        }

        foreach ($ips as $ip) {
            if (!$this->ipValidator->isPublic($ip)) {
                throw new SsrfBlockedException(sprintf('Host "%s" resolves to non-public address %s', $host, $ip));
            }
        }

        return $ips;
    }
}
