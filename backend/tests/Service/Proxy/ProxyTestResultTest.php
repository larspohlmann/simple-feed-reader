<?php

declare(strict_types=1);

namespace App\Tests\Service\Proxy;

use App\Service\Proxy\ProxyTestFailure;
use App\Service\Proxy\ProxyTestResult;
use PHPUnit\Framework\TestCase;

final class ProxyTestResultTest extends TestCase
{
    public function testOkResultCarriesTheEgressIpAndNoReason(): void
    {
        $result = ProxyTestResult::ok('203.0.113.7');

        self::assertTrue($result->ok);
        self::assertSame('203.0.113.7', $result->egressIp);
        self::assertNull($result->failure);
        self::assertSame(
            ['ok' => true, 'egressIp' => '203.0.113.7', 'reason' => null],
            $result->toArray(),
        );
    }

    public function testAGuardFailureSendsItsCodeAsTheReason(): void
    {
        $result = ProxyTestResult::failed(ProxyTestFailure::NotConfigured);

        self::assertFalse($result->ok);
        self::assertNull($result->egressIp);
        self::assertSame(ProxyTestFailure::NotConfigured, $result->failure);
        self::assertSame(
            ['ok' => false, 'egressIp' => null, 'reason' => 'not_configured'],
            $result->toArray(),
        );
    }

    public function testAFailureWithADetailSendsTheDetailAsTheReason(): void
    {
        $result = ProxyTestResult::failed(ProxyTestFailure::UnexpectedStatus, 'HTTP 404');

        self::assertSame(
            ['ok' => false, 'egressIp' => null, 'reason' => 'HTTP 404'],
            $result->toArray(),
        );
    }
}
