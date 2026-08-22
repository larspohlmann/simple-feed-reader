<?php

declare(strict_types=1);

namespace App\Tests\Service\Proxy;

use App\Service\Proxy\ProxyTestResult;
use PHPUnit\Framework\TestCase;

final class ProxyTestResultTest extends TestCase
{
    public function testOkResultCarriesTheEgressIpAndNoReason(): void
    {
        $result = ProxyTestResult::ok('203.0.113.7');

        self::assertTrue($result->ok);
        self::assertSame('203.0.113.7', $result->egressIp);
        self::assertNull($result->reason);
        self::assertSame(
            ['ok' => true, 'egressIp' => '203.0.113.7', 'reason' => null],
            $result->toArray(),
        );
    }

    public function testFailedResultCarriesTheReasonAndNoEgressIp(): void
    {
        $result = ProxyTestResult::failed('not_configured');

        self::assertFalse($result->ok);
        self::assertNull($result->egressIp);
        self::assertSame('not_configured', $result->reason);
        self::assertSame(
            ['ok' => false, 'egressIp' => null, 'reason' => 'not_configured'],
            $result->toArray(),
        );
    }
}
