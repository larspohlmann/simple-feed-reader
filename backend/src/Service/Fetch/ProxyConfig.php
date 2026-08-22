<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use App\Enum\ProxyType;

/**
 * A resolved egress proxy. Holds the opened password only for the life of one
 * fetch flow, and builds the single curl proxy URL both fetch paths and the
 * connection tester share. SOCKS5 uses the remote-DNS scheme (socks5h) so the
 * name resolves at the proxy — no DNS leak, and geo-restricted hosts resolve
 * from the proxy's vantage point.
 */
final readonly class ProxyConfig
{
    public function __construct(
        public ProxyType $type,
        public string $host,
        public int $port,
        public ?string $username,
        public ?string $password,
        // On by default. When false, a proxied fetch that fails is terminal — the
        // fetchers never retry directly, so the real server IP is never revealed.
        public bool $directFallback = true,
    ) {
    }

    public function dsn(): string
    {
        $scheme = ProxyType::Socks5 === $this->type ? 'socks5h' : 'http';

        return sprintf('%s://%s%s:%d', $scheme, $this->credentials(), $this->host, $this->port);
    }

    private function credentials(): string
    {
        if (null === $this->username) {
            return '';
        }

        return sprintf('%s:%s@', rawurlencode($this->username), rawurlencode($this->password ?? ''));
    }
}
