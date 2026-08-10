<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Service\Fetch\CrossFamilyFailover;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;

final class CrossFamilyFailoverTest extends TestCase
{
    public function testAConnectionResetWarrantsAnotherFamily(): void
    {
        self::assertTrue(CrossFamilyFailover::isWarranted(new TransportException('Connection reset by peer')));
    }

    public function testATimeoutDoesNotWarrantAnotherFamily(): void
    {
        // A timeout means the family answered the connect but is slow, not that
        // the route is dead; re-driving every family would only multiply the wait.
        self::assertFalse(CrossFamilyFailover::isWarranted(new TimeoutException('Idle timeout reached')));
    }

    public function testAnAbsentTransportErrorWarrantsNothing(): void
    {
        self::assertFalse(CrossFamilyFailover::isWarranted(null));
    }

    public function testAClientOrServerErrorStatusWarrantsAnotherFamily(): void
    {
        // A 403 from taz over IPv6 while IPv4 serves 200 is an address-family
        // block, not a genuine refusal — the other family is worth a try.
        self::assertTrue(CrossFamilyFailover::isRetryableStatus(400));
        self::assertTrue(CrossFamilyFailover::isRetryableStatus(403));
        self::assertTrue(CrossFamilyFailover::isRetryableStatus(503));
    }

    public function testASuccessOrRedirectStatusDoesNotWarrantAnotherFamily(): void
    {
        // 2xx and 304 are answers; 3xx is a redirect the caller follows. None is
        // a failure to route around.
        self::assertFalse(CrossFamilyFailover::isRetryableStatus(200));
        self::assertFalse(CrossFamilyFailover::isRetryableStatus(304));
        self::assertFalse(CrossFamilyFailover::isRetryableStatus(301));
    }

    public function testForcesAFreshConnectionOnlyAfterTheFirstAttempt(): void
    {
        // The first attempt reuses the connection pool as normal; a retry must
        // open its own connection so curl cannot reuse the dead family's socket.
        self::assertSame([], CrossFamilyFailover::freshConnectionAfter(0));
        self::assertSame(
            ['extra' => ['curl' => [\CURLOPT_FRESH_CONNECT => true]]],
            CrossFamilyFailover::freshConnectionAfter(1),
        );
    }
}
