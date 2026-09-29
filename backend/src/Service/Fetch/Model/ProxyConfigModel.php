<?php

declare(strict_types=1);

namespace App\Service\Fetch\Model;

use App\Enum\ProxyType;

/**
 * A resolved egress proxy. Holds the opened password only for the life of one
 * fetch flow, and builds the single curl proxy URL both fetch paths and the
 * connection tester share.
 */
final readonly class ProxyConfigModel
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
        // Off by default, so curl resolves names here. `socks5h` hands them to the proxy, which only works on a proxy
        // that resolves names: Private Internet Access answers every name with "host unreachable" (#490).
        public bool $remoteDns = false,
    ) {
    }

    public function dsn(): string
    {
        $scheme = ProxyType::Socks5 === $this->type ? $this->socksScheme() : 'http';

        return sprintf('%s://%s%s:%d', $scheme, $this->credentials(), $this->host, $this->port);
    }

    /** Stable while the settings a proxied SMTP send reads are, so its transport is rebuilt only when they change.
     *  The password enters as a digest: the plaintext must not leave this object. */
    public function signature(): string
    {
        return implode('|', [
            $this->type->value,
            $this->host,
            (string) $this->port,
            $this->username ?? '',
            null === $this->password ? 'no-pass' : hash('sha256', $this->password),
            $this->remoteDns ? 'remote-dns' : 'local-dns',
        ]);
    }

    public function resolvesLocally(): bool
    {
        return ProxyType::Socks5 === $this->type && !$this->remoteDns;
    }

    private function socksScheme(): string
    {
        return $this->remoteDns ? 'socks5h' : 'socks5';
    }

    private function credentials(): string
    {
        if (null === $this->username) {
            return '';
        }

        return sprintf('%s:%s@', rawurlencode($this->username), rawurlencode($this->password ?? ''));
    }
}
