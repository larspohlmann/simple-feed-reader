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
     * The `resolve` pin for this family attempt, plus a connect-to alias for exactly these addresses. curl pools
     * connections by host and port, and a pooled connection ignores `resolve`; the connect-to target is part of a
     * connection's identity, so a connection is reused only by a request with the same pin.
     *
     * @return array{resolve: array<string, string>, extra: array{curl: array<int, list<string>>}}
     */
    public static function pinned(GuardedUrlModel $guarded, int $pinAttempt): array
    {
        $pins = $guarded->pinnedAddressAttempts();
        $pinnedAddresses = $pins[min($pinAttempt, \count($pins) - 1)];
        $alias = sprintf('pin-%s.invalid', substr(hash('sha256', $pinnedAddresses), 0, 16));

        $connectTo = sprintf('%s::%s:', self::connectToHost($guarded->host), $alias);

        return [
            'resolve' => [$guarded->host => $pinnedAddresses, $alias => $pinnedAddresses],
            'extra' => ['curl' => [\CURLOPT_CONNECT_TO => [$connectTo]]],
        ];
    }

    /**
     * Request options overlaid with egress options; `extra` is merged, not replaced, so a caller's
     * `use_persistent_connections` survives the pin's `extra.curl`.
     *
     * @param array<string, mixed> $options
     * @param array<string, mixed> $egress
     *
     * @return array<string, mixed>
     */
    public static function merge(array $options, array $egress): array
    {
        $merged = [...$options, ...$egress];
        $extra = array_replace_recursive(self::extraOf($options), self::extraOf($egress));
        if ([] !== $extra) {
            $merged['extra'] = $extra;
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<mixed>
     */
    private static function extraOf(array $options): array
    {
        $extra = $options['extra'] ?? [];

        return \is_array($extra) ? $extra : [];
    }

    private static function connectToHost(string $host): string
    {
        return str_contains($host, ':') ? '[' . $host . ']' : $host;
    }

    private function __construct()
    {
    }
}
