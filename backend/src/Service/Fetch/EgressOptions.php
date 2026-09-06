<?php

declare(strict_types=1);

namespace App\Service\Fetch;

/**
 * The single place the "how do we reach the host" request options are built, so
 * the proxy-vs-pin rule lives once for both fetch builders. The invariant: the
 * caller has ALREADY run UrlGuard::assertSafe (the host guard is kept on both
 * paths); this only chooses the transport. Proxied drops the IP pin — impossible
 * through socks5h, where DNS resolves at the proxy — and keeps everything else.
 */
final class EgressOptions
{
    /**
     * `no_proxy` is pinned empty on purpose. Left unset, curl falls back to the
     * ambient no_proxy/NO_PROXY environment variable and sends a matching host
     * DIRECT — succeeding silently, with no transport failure for the caller to
     * notice, which would defeat the whole point of `directFallback` off.
     *
     * @return array{proxy: string, no_proxy: string, extra?: array{curl: array<int, int>}}
     */
    public static function proxied(ProxyConfig $proxy): array
    {
        $options = ['proxy' => $proxy->dsn(), 'no_proxy' => ''];
        if (!$proxy->resolvesLocally()) {
            return $options;
        }

        // Hand the proxy an IPv4 address; an IPv4-only SOCKS5 rejects a locally
        // resolved IPv6 on a dual-stack host (#861, as CurlSmtpOptions).
        $options['extra'] = ['curl' => [\CURLOPT_IPRESOLVE => \CURL_IPRESOLVE_V4]];

        return $options;
    }

    /**
     * @return array<string, mixed> the `resolve` pin for this family attempt plus
     *                              the cross-family fresh-connection extra
     */
    public static function pinned(GuardedUrl $guarded, int $pinAttempt): array
    {
        $pins = $guarded->pinnedAddressAttempts();
        $pinnedAddresses = $pins[min($pinAttempt, \count($pins) - 1)];

        return [
            'resolve' => [$guarded->host => $pinnedAddresses],
            ...CrossFamilyFailover::freshConnectionAfter($pinAttempt),
        ];
    }
}
