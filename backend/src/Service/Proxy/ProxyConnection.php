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
