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

    public function testAddressAttemptsForOneFamilyAreJustTheFullPin(): void
    {
        $guarded = new GuardedUrl('example.com', ['93.184.216.34', '93.184.216.35']);

        // Both addresses share a family, so a per-family retry would only repeat
        // the pin the client already tried — one attempt is all there is.
        self::assertSame(['93.184.216.34,93.184.216.35'], $guarded->pinnedAddressAttempts());
    }

    public function testAddressAttemptsForDualStackTryBothThenEachFamilyAlone(): void
    {
        $guarded = new GuardedUrl('dual.example.com', ['2606:2800:220:1:248:1893:25c8:1946', '93.184.216.34']);

        self::assertSame(
            [
                // Both families: the client's happy-eyeballs races them.
                '2606:2800:220:1:248:1893:25c8:1946,93.184.216.34',
                // IPv4 alone: rescues a request whose IPv6 connected but then died
                // before the response (heise's IPv6 from Strato resets at TLS).
                '93.184.216.34',
                // IPv6 alone: the mirror case.
                '2606:2800:220:1:248:1893:25c8:1946',
            ],
            $guarded->pinnedAddressAttempts(),
        );
    }
}
