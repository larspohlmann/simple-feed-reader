<?php

declare(strict_types=1);

namespace App\Tests\Service\Logging\Loki;

use App\Service\Logging\Loki\EnvLokiEndpoint;
use PHPUnit\Framework\TestCase;

final class EnvLokiEndpointTest extends TestCase
{
    public function testReturnsNullForEmptyConfiguration(): void
    {
        $endpoint = new EnvLokiEndpoint('', '', '');

        self::assertNull($endpoint->pushUrl());
        self::assertNull($endpoint->username());
        self::assertNull($endpoint->token());
    }

    public function testReturnsConfiguredValues(): void
    {
        $endpoint = new EnvLokiEndpoint('http://loki:3100/loki/api/v1/push', 'user', 'secret');

        self::assertSame('http://loki:3100/loki/api/v1/push', $endpoint->pushUrl());
        self::assertSame('user', $endpoint->username());
        self::assertSame('secret', $endpoint->token());
    }
}
