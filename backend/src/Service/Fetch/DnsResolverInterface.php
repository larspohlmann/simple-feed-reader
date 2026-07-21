<?php

declare(strict_types=1);

namespace App\Service\Fetch;

interface DnsResolverInterface
{
    /**
     * @return list<string> IPv4/IPv6 addresses; empty when resolution fails
     */
    public function resolve(string $hostname): array;
}
