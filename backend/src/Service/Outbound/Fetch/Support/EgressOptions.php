<?php

declare(strict_types=1);

namespace App\Service\Outbound\Fetch\Support;

use App\Service\Outbound\Fetch\Model\GuardedUrlModel;
use App\Service\Outbound\Fetch\Model\ProxyConfigModel;

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
     * A pooled curl connection ignores `resolve`; the per-address-set connect-to alias keeps it from being reused under
     * a different pin.
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
     * Egress options overlay the request's; `extra` merges and `extra.curl` replaces per option.
     *
     * @param array<string, mixed> $options
     * @param array<string, mixed> $egress
     *
     * @return array<string, mixed>
     */
    public static function merge(array $options, array $egress): array
    {
        $merged = [...$options, ...$egress];
        $extra = array_replace(self::extraOf($options), self::extraOf($egress));
        $curl = array_replace(self::curlOf($options), self::curlOf($egress));
        if ([] !== $curl) {
            $extra['curl'] = $curl;
        }
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
        return self::arrayAt($options, 'extra');
    }

    /**
     * @param array<mixed> $options
     *
     * @return array<mixed>
     */
    private static function arrayAt(array $options, string $key): array
    {
        $value = $options[$key] ?? [];

        return \is_array($value) ? $value : [];
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<mixed>
     */
    private static function curlOf(array $options): array
    {
        return self::arrayAt(self::extraOf($options), 'curl');
    }

    private static function connectToHost(string $host): string
    {
        return str_contains($host, ':') ? '[' . $host . ']' : $host;
    }

    private function __construct()
    {
    }
}
