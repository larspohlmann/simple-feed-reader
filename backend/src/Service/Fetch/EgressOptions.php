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
    /** @return array{proxy: string} */
    public static function proxied(ProxyConfig $proxy): array
    {
        return ['proxy' => $proxy->dsn()];
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
