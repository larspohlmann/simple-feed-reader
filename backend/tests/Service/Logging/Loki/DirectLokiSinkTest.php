<?php

declare(strict_types=1);

namespace App\Tests\Service\Logging\Loki;

use App\Service\Logging\Loki\DirectLokiSink;
use App\Service\Logging\Loki\LokiClient;
use App\Service\Logging\Loki\LokiEndpoint;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DirectLokiSinkTest extends TestCase
{
    public function testForwardsLinesToLokiClientAsAnHttpPush(): void
    {
        $seen = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = $options;

            return new MockResponse('', ['http_code' => 204]);
        });
        $sink = new DirectLokiSink(new LokiClient($http, $this->endpoint()));

        $sink->write([[
            'ts' => '1700000000000000000',
            'line' => '{"message":"hello"}',
            'labels' => ['app' => 'sfr', 'env' => 'prod'],
        ]]);

        /** @var array{body: string} $seen */
        $body = json_decode($seen['body'], true, 512, JSON_THROW_ON_ERROR);
        /** @var array{streams: list<array{values: list<array{0: string, 1: string}>}>} $body */
        self::assertSame('{"message":"hello"}', $body['streams'][0]['values'][0][1]);
    }

    private function endpoint(): LokiEndpoint
    {
        return new class implements LokiEndpoint {
            public function pushUrl(): string
            {
                return 'http://loki:3100/loki/api/v1/push';
            }

            public function username(): ?string
            {
                return null;
            }

            public function token(): ?string
            {
                return null;
            }
        };
    }
}
