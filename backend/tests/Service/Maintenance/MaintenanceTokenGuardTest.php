<?php

declare(strict_types=1);

namespace App\Tests\Service\Maintenance;

use App\Service\Maintenance\MaintenanceTokenGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class MaintenanceTokenGuardTest extends TestCase
{
    public function testAnEmptyConfiguredTokenDeniesEverything(): void
    {
        $guard = new MaintenanceTokenGuard('');

        $this->assertFalse($guard->isAuthorized($this->requestWithHeader('anything')));
        $this->assertFalse($guard->isAuthorized($this->requestWithQuery('anything')));
    }

    public function testTheCorrectTokenInTheHeaderIsAuthorized(): void
    {
        $guard = new MaintenanceTokenGuard('secret');

        $this->assertTrue($guard->isAuthorized($this->requestWithHeader('secret')));
    }

    public function testTheCorrectTokenInTheQueryIsAuthorized(): void
    {
        $guard = new MaintenanceTokenGuard('secret');

        $this->assertTrue($guard->isAuthorized($this->requestWithQuery('secret')));
    }

    public function testAWrongTokenIsDenied(): void
    {
        $guard = new MaintenanceTokenGuard('secret');

        $this->assertFalse($guard->isAuthorized($this->requestWithHeader('wrong')));
    }

    public function testAnAbsentTokenIsDenied(): void
    {
        $guard = new MaintenanceTokenGuard('secret');

        $this->assertFalse($guard->isAuthorized(Request::create('/maintenance/refresh', 'POST')));
    }

    private function requestWithHeader(string $token): Request
    {
        $request = Request::create('/maintenance/refresh', 'POST');
        $request->headers->set('X-Maintenance-Token', $token);

        return $request;
    }

    private function requestWithQuery(string $token): Request
    {
        return Request::create('/maintenance/refresh?token=' . $token, 'POST');
    }
}
