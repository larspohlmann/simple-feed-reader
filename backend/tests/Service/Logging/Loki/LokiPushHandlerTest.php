<?php

declare(strict_types=1);

namespace App\Tests\Service\Logging\Loki;

use App\Service\Logging\Loki\LokiClient;
use App\Service\Logging\Loki\LokiEndpoint;
use App\Service\Logging\Loki\LokiPushHandler;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class LokiPushHandlerTest extends TestCase
{
    public function testBuffersUntilFlushThenPostsLabelledLines(): void
    {
        $seen = [];
        $http = new MockHttpClient(function (string $m, string $u, array $o) use (&$seen): MockResponse {
            $seen = $o;

            return new MockResponse('', ['http_code' => 204]);
        });
        $handler = new LokiPushHandler(new LokiClient($http, $this->endpoint()), 'sfr', 'prod', Level::Info, 100);

        $handler->handle($this->record(Level::Info, 'app', 'hello'));
        self::assertSame([], $seen, 'must buffer, not post per record');

        $handler->flush();

        /** @var array{body: string} $seen */
        $body = json_decode($seen['body'], true, 512, JSON_THROW_ON_ERROR);
        /**
         * @var array{
         *     streams: list<array{
         *         stream: array{app: string, env: string, channel: string, level: string, source: string},
         *         values: list<array{0: string, 1: string}>,
         *     }>,
         * } $body
         */
        $stream = $body['streams'][0];
        self::assertSame('sfr', $stream['stream']['app']);
        self::assertSame('prod', $stream['stream']['env']);
        self::assertSame('app', $stream['stream']['channel']);
        self::assertSame('info', $stream['stream']['level']);
        self::assertSame('backend', $stream['stream']['source']);

        /** @var array{message: string} $decodedLine */
        $decodedLine = json_decode($stream['values'][0][1], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('hello', $decodedLine['message']);
    }

    public function testAutoFlushesAtThreshold(): void
    {
        $posts = 0;
        $http = new MockHttpClient(function () use (&$posts): MockResponse {
            ++$posts;

            return new MockResponse('', ['http_code' => 204]);
        });
        $handler = new LokiPushHandler(new LokiClient($http, $this->endpoint()), 'sfr', 'prod', Level::Info, 2);

        $handler->handle($this->record(Level::Info, 'app', 'one'));
        $handler->handle($this->record(Level::Info, 'app', 'two'));

        self::assertSame(1, $posts);
    }

    public function testDropsBelowLevelFloor(): void
    {
        $posts = 0;
        $http = new MockHttpClient(function () use (&$posts): MockResponse {
            ++$posts;

            return new MockResponse('', ['http_code' => 204]);
        });
        $handler = new LokiPushHandler(new LokiClient($http, $this->endpoint()), 'sfr', 'prod', Level::Info, 100);

        $handler->handle($this->record(Level::Debug, 'app', 'noise'));
        $handler->flush();

        self::assertSame(0, $posts);
    }

    private function record(Level $level, string $channel, string $message): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable(), $channel, $level, $message, [], ['request_id' => '01TEST']);
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
