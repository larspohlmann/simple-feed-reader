<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ProxyType;

/** The non-secret proxy fields. The sealed password travels separately, because an update may leave it out. */
final readonly class ProxyConnection
{
    /** The SOCKS5 port a fresh row starts from. */
    public const int DEFAULT_PORT = 1080;

    public function __construct(
        public bool $enabled,
        public bool $directFallback,
        public ProxyType $type,
        public string $host,
        public int $port,
        public ?string $username,
        public bool $remoteDns = false,
    ) {
    }
}
