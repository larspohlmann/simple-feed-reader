<?php

declare(strict_types=1);

namespace App\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Smoke test for the e2e harness itself: can we reach the running stack over
 * real (mkcert-trusted) TLS and get the health endpoint? If this fails, the
 * stack is down or TLS trust is missing — fix that before reading any other
 * e2e failure.
 */
final class HealthE2eTest extends TestCase
{
    public function testHealthEndpointIsOkOverTls(): void
    {
        $baseUrl = $_ENV['E2E_BASE_URL'] ?? null;
        if (!is_string($baseUrl)) {
            self::fail('E2E_BASE_URL environment variable must be set to a string.');
        }

        $client = HttpClient::create();

        $response = $client->request('GET', $baseUrl . '/api/health');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaders()['content-type'][0]);
        self::assertSame(['status' => 'ok'], $response->toArray());
    }
}
