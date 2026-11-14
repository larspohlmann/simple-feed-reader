<?php

declare(strict_types=1);

namespace App\Tests\Service\Proxy\Model;

use App\Service\Proxy\Model\ProxyTestFailure;
use App\Service\Proxy\Model\ProxyTestResultModel;
use PHPUnit\Framework\TestCase;

final class ProxyTestResultModelTest extends TestCase
{
    public function testOkResultCarriesTheEgressIpAndNoReason(): void
    {
        $result = ProxyTestResultModel::ok('203.0.113.7');

        self::assertTrue($result->ok);
        self::assertSame('203.0.113.7', $result->egressIp);
        self::assertNull($result->failure);
    }

    public function testAGuardFailureCarriesItsCode(): void
    {
        $result = ProxyTestResultModel::failed(ProxyTestFailure::NotConfigured);

        self::assertFalse($result->ok);
        self::assertNull($result->egressIp);
        self::assertSame(ProxyTestFailure::NotConfigured, $result->failure);
    }

    public function testAFailureWithADetailCarriesItsDetail(): void
    {
        $result = ProxyTestResultModel::failed(ProxyTestFailure::UnexpectedStatus, 'HTTP 404');

        self::assertSame('HTTP 404', $result->detail);
        self::assertSame(ProxyTestFailure::UnexpectedStatus, $result->failure);
    }
}
