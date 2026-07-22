<?php

declare(strict_types=1);

namespace App\Tests\E2e;

use App\Tests\E2e\Support\E2eTestCase;

/**
 * Smoke test for the e2e harness itself: can we reach the running stack over
 * real (mkcert-trusted) TLS and get the health endpoint? If this fails, the
 * stack is down or TLS trust is missing — fix that before reading any other
 * e2e failure.
 */
final class HealthE2eTest extends E2eTestCase
{
    public function testHealthEndpointIsOkOverTls(): void
    {
        $response = $this->getJson('/api/health');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaders()['content-type'][0]);
        self::assertSame(['status' => 'ok'], $response->toArray());
    }
}
