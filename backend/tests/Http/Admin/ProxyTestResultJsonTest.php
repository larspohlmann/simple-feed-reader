<?php

declare(strict_types=1);

namespace App\Tests\Http\Admin;

use App\Http\Admin\ProxyTestResultJson;
use App\Service\Proxy\Model\ProxyTestFailure;
use App\Service\Proxy\Model\ProxyTestResultModel;
use PHPUnit\Framework\TestCase;

final class ProxyTestResultJsonTest extends TestCase
{
    public function testAnOkResultSendsTheEgressIpAndNoReason(): void
    {
        self::assertSame(
            ['ok' => true, 'egressIp' => '203.0.113.7', 'reason' => null],
            ProxyTestResultJson::from(ProxyTestResultModel::ok('203.0.113.7')),
        );
    }

    public function testAGuardFailureSendsItsCodeAsTheReason(): void
    {
        self::assertSame(
            ['ok' => false, 'egressIp' => null, 'reason' => 'not_configured'],
            ProxyTestResultJson::from(ProxyTestResultModel::failed(ProxyTestFailure::NotConfigured)),
        );
    }

    public function testAFailureWithADetailSendsTheDetailAsTheReason(): void
    {
        self::assertSame(
            ['ok' => false, 'egressIp' => null, 'reason' => 'HTTP 404'],
            ProxyTestResultJson::from(ProxyTestResultModel::failed(ProxyTestFailure::UnexpectedStatus, 'HTTP 404')),
        );
    }
}
