<?php

declare(strict_types=1);

namespace App\Tests\Service\Logging\Loki;

use App\Service\Logging\Loki\LokiClient;
use App\Service\Logging\Loki\LokiEndpoint;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class LokiClientTest extends TestCase
{
    public function testPostsGroupedStreamsWithBasicAuthAndTimeout(): void
    {
        $seen = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse('', ['http_code' => 204]);
        });

        $client = new LokiClient($http, $this->endpoint('http://loki:3100/loki/api/v1/push', 'u', 't'));
        $client->push([
            ['ts' => '1700000000000000000', 'line' => '{"m":"a"}', 'labels' => ['app' => 'sfr', 'level' => 'info']],
            ['ts' => '1700000000000000001', 'line' => '{"m":"b"}', 'labels' => ['app' => 'sfr', 'level' => 'info']],
        ]);

        /**
         * @var array{
         *     method: string,
         *     url: string,
         *     options: array{
         *         headers: list<string>,
         *         body: string,
         *         timeout: float,
         *         normalized_headers: array{authorization: list<string>},
         *     },
         * } $seen
         */
        self::assertSame('POST', $seen['method']);
        self::assertSame('http://loki:3100/loki/api/v1/push', $seen['url']);
        self::assertSame(1.0, $seen['options']['timeout']);
        self::assertContains('Content-Type: application/json', $seen['options']['headers']);
        // Asserted as a whole payload rather than key by key, so a stream
        // silently gaining or losing an entry cannot pass unnoticed.
        self::assertSame(
            [
                'streams' => [
                    [
                        'stream' => ['app' => 'sfr', 'level' => 'info'],
                        'values' => [
                            ['1700000000000000000', '{"m":"a"}'],
                            ['1700000000000000001', '{"m":"b"}'],
                        ],
                    ],
                ],
            ],
            json_decode($seen['options']['body'], true, 512, JSON_THROW_ON_ERROR),
        );
        // MockHttpClient's HttpClientTrait::prepareRequest() normalizes
        // "auth_basic" into an "Authorization: Basic" header and removes the
        // option itself, so the credentials show up there instead.
        self::assertSame(
            ['Authorization: Basic ' . base64_encode('u:t')],
            $seen['options']['normalized_headers']['authorization'],
        );
    }

    public function testSwallowsTransportErrors(): void
    {
        $this->expectNotToPerformAssertions();

        $http = new MockHttpClient(function (): MockResponse {
            throw new \RuntimeException('loki down');
        });
        $client = new LokiClient($http, $this->endpoint('http://loki:3100/loki/api/v1/push', null, null));

        $client->push([['ts' => '1', 'line' => '{}', 'labels' => ['app' => 'sfr']]]);
    }

    public function testSwallowsErrorsFromResolvingTheEndpoint(): void
    {
        $this->expectNotToPerformAssertions();

        $http = new MockHttpClient(fn (): MockResponse => new MockResponse('', ['http_code' => 204]));
        $endpoint = new class implements LokiEndpoint {
            public function pushUrl(): ?string
            {
                throw new \RuntimeException('settings unavailable');
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
        $client = new LokiClient($http, $endpoint);

        $client->push([['ts' => '1', 'line' => '{}', 'labels' => ['app' => 'sfr']]]);
    }

    public function testDoesNothingWhenNoUrlConfigured(): void
    {
        $calls = 0;
        $http = new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse();
        });
        $client = new LokiClient($http, $this->endpoint(null, null, null));

        $client->push([['ts' => '1', 'line' => '{}', 'labels' => ['app' => 'sfr']]]);

        self::assertSame(0, $calls);
    }

    public function testDoesNothingWhenLinesAreEmpty(): void
    {
        $calls = 0;
        $http = new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse();
        });
        $client = new LokiClient($http, $this->endpoint('http://loki:3100/loki/api/v1/push', null, null));

        $client->push([]);

        self::assertSame(0, $calls);
    }

    public function testOmitsBasicAuthWhenOnlyOneCredentialIsConfigured(): void
    {
        $seen = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = $options;

            return new MockResponse('', ['http_code' => 204]);
        });
        $client = new LokiClient($http, $this->endpoint('http://loki:3100/loki/api/v1/push', 'u', null));

        $client->push([['ts' => '1', 'line' => '{}', 'labels' => ['app' => 'sfr']]]);

        /** @var array{normalized_headers: array<string, mixed>} $seen */
        self::assertArrayNotHasKey('authorization', $seen['normalized_headers']);
    }

    private function endpoint(?string $url, ?string $user, ?string $token): LokiEndpoint
    {
        return new class ($url, $user, $token) implements LokiEndpoint {
            public function __construct(private ?string $url, private ?string $user, private ?string $token)
            {
            }

            public function pushUrl(): ?string
            {
                return $this->url;
            }

            public function username(): ?string
            {
                return $this->user;
            }

            public function token(): ?string
            {
                return $this->token;
            }
        };
    }
}
