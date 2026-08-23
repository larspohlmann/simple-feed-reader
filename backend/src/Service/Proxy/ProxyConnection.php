<?php

declare(strict_types=1);

namespace App\Service\Proxy;

use App\Enum\ProxyType;

/**
 * The non-secret proxy connection fields, carried as one value so the entity
 * mutators and the service take a single argument instead of a long positional
 * list. The sealed password travels separately (it may be absent on an update).
 */
final readonly class ProxyConnection
{
    /**
     * The SOCKS5 well-known port. The one definition the entity default, the
     * request DTO and the "not configured yet" payload all read, so a fresh
     * instance cannot describe itself three different ways.
     */
    public const int DEFAULT_PORT = 1080;

    public function __construct(
        public bool $enabled,
        public bool $directFallback,
        public ProxyType $type,
        public string $host,
        public int $port,
        public ?string $username,
    ) {
    }
}
