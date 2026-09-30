<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Fetch\DnsResolver\DnsResolverInterface;

final readonly class StaticDnsResolver implements DnsResolverInterface
{
    /** @param array<string, list<string>> $addresses */
    public function __construct(private array $addresses)
    {
    }

    public function resolve(string $hostname): array
    {
        return $this->addresses[$hostname] ?? [];
    }
}
