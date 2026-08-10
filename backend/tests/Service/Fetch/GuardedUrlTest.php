<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Service\Fetch\GuardedUrl;
use PHPUnit\Framework\TestCase;

final class GuardedUrlTest extends TestCase
{
    public function testPinnedAddressesJoinsEveryValidatedAddressForCurlResolve(): void
    {
        $guarded = new GuardedUrl('dual.example.com', ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946']);

        self::assertSame('93.184.216.34,2606:2800:220:1:248:1893:25c8:1946', $guarded->pinnedAddresses());
    }

    public function testPinnedAddressesOfASingleAddressCarriesNoSeparator(): void
    {
        $guarded = new GuardedUrl('example.com', ['93.184.216.34']);

        self::assertSame('93.184.216.34', $guarded->pinnedAddresses());
    }
}
