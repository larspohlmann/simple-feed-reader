<?php

declare(strict_types=1);

namespace App\Service\Fetch\Support;

use App\Service\Fetch\Model\GuardedUrlModel;
use App\Service\Fetch\Model\ProxyConfigModel;

/**
 * The "how do we reach the host" request options, built once for both fetch paths. The caller has already run
 * UrlGuard::assertSafe; proxied drops only the IP pin, which socks5h cannot honour.
 */
final class EgressOptions
{
    /**
     * `no_proxy` stays pinned empty: unset, curl reads the ambient NO_PROXY and silently sends a matching host direct,
     * which defeats `directFallback` off.
     *
     * @return array{proxy: string, no_proxy: string, extra?: array{curl: array<int, int>}}
     */
    public static function proxied(ProxyConfigModel $proxy): array
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
    public static function pinned(GuardedUrlModel $guarded, int $pinAttempt): array
    {
        $pins = $guarded->pinnedAddressAttempts();
        $pinnedAddresses = $pins[min($pinAttempt, \count($pins) - 1)];

        return [
            'resolve' => [$guarded->host => $pinnedAddresses],
            ...self::freshConnectionAfter($pinAttempt),
        ];
    }

    /**
     * A failover retry gets its own connection: curl pools by host:port, so a retry pinned to a new family would
     * otherwise reuse the old family's keep-alive connection and ignore the pin.
     *
     * @return array{extra?: array{curl: array<int, bool>}}
     */
    private static function freshConnectionAfter(int $attemptIndex): array
    {
        return $attemptIndex > 0
            ? ['extra' => ['curl' => [\CURLOPT_FRESH_CONNECT => true]]]
            : [];
    }

    private function __construct()
    {
    }
}
