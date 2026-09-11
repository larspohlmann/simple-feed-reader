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

        $rawLine = $stream['values'][0][1];
        self::assertStringEndsNotWith("\n", $rawLine);

        /** @var array{message: string, context: array<string, mixed>} $decodedLine */
        $decodedLine = json_decode($rawLine, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('hello', $decodedLine['message']);
        self::assertArrayHasKey('context', $decodedLine);
    }

    public function testHandleReturnsFalseSoOtherHandlersStillReceiveTheRecord(): void
    {
        $handler = new LokiPushHandler(new LokiClient(new MockHttpClient(), $this->endpoint()), 'sfr', 'prod');

        $bubbles = $handler->handle($this->record(Level::Info, 'app', 'hello'));

        self::assertFalse($bubbles);
    }

    public function testDefaultFlushThresholdIsOneHundred(): void
    {
        $posts = 0;
        $http = new MockHttpClient(function () use (&$posts): MockResponse {
            ++$posts;

            return new MockResponse('', ['http_code' => 204]);
        });
        $handler = new LokiPushHandler(new LokiClient($http, $this->endpoint()), 'sfr', 'prod');

        for ($i = 0; $i < 99; ++$i) {
            $handler->handle($this->record(Level::Info, 'app', 'line'));
        }
        self::assertSame(0, $posts, 'must not flush before the 100th record');

        $handler->handle($this->record(Level::Info, 'app', 'line'));

        self::assertSame(1, $posts, 'must flush on the 100th record');
    }

    public function testResetFlushesPendingLines(): void
    {
        $posts = 0;
        $http = new MockHttpClient(function () use (&$posts): MockResponse {
            ++$posts;

            return new MockResponse('', ['http_code' => 204]);
        });
        $handler = new LokiPushHandler(new LokiClient($http, $this->endpoint()), 'sfr', 'prod', Level::Info, 100);
        $handler->handle($this->record(Level::Info, 'app', 'pending'));

        $handler->reset();

        self::assertSame(1, $posts);
    }

    public function testCloseFlushesPendingLines(): void
    {
        $posts = 0;
        $http = new MockHttpClient(function () use (&$posts): MockResponse {
            ++$posts;

            return new MockResponse('', ['http_code' => 204]);
        });
        $handler = new LokiPushHandler(new LokiClient($http, $this->endpoint()), 'sfr', 'prod', Level::Info, 100);
        $handler->handle($this->record(Level::Info, 'app', 'pending'));

        $handler->close();

        self::assertSame(1, $posts);
    }

    public function testFormatsContextExceptionsWithStackTraces(): void
    {
        $seen = [];
        $http = new MockHttpClient(function (string $m, string $u, array $o) use (&$seen): MockResponse {
            $seen = $o;

            return new MockResponse('', ['http_code' => 204]);
        });
        $handler = new LokiPushHandler(new LokiClient($http, $this->endpoint()), 'sfr', 'prod', Level::Info, 100);

        $handler->handle(new LogRecord(
            new \DateTimeImmutable(),
            'app',
            Level::Error,
            'boom',
            ['exception' => new \RuntimeException('boom')],
        ));
        $handler->flush();

        /** @var array{body: string} $seen */
        $body = json_decode($seen['body'], true, 512, JSON_THROW_ON_ERROR);
        /** @var array{streams: list<array{values: list<array{0: string, 1: string}>}>} $body */
        $rawLine = $body['streams'][0]['values'][0][1];

        /** @var array{context: array{exception: array<string, mixed>}} $decodedLine */
        $decodedLine = json_decode($rawLine, true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('trace', $decodedLine['context']['exception']);
    }

    public function testFormatsNanosecondPrecisionTimestamps(): void
    {
        $seen = [];
        $http = new MockHttpClient(function (string $m, string $u, array $o) use (&$seen): MockResponse {
            $seen = $o;

            return new MockResponse('', ['http_code' => 204]);
        });
        $handler = new LokiPushHandler(new LokiClient($http, $this->endpoint()), 'sfr', 'prod', Level::Info, 100);

        $time = new \DateTimeImmutable('2024-03-05T10:20:30.123456+00:00');
        $handler->handle(new LogRecord($time, 'app', Level::Info, 'hello'));
        $handler->flush();

        /** @var array{body: string} $seen */
        $body = json_decode($seen['body'], true, 512, JSON_THROW_ON_ERROR);
        /** @var array{streams: list<array{values: list<array{0: string, 1: string}>}>} $body */
        $ts = $body['streams'][0]['values'][0][0];

        self::assertSame($time->getTimestamp() . '123456000', $ts);
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

    public function testLabelsSourceFrontendForTheClientErrorsChannel(): void
    {
        $seen = [];
        $http = new MockHttpClient(function (string $m, string $u, array $o) use (&$seen): MockResponse {
            $seen = $o;

            return new MockResponse('', ['http_code' => 204]);
        });
        $handler = new LokiPushHandler(new LokiClient($http, $this->endpoint()), 'sfr', 'prod', Level::Info, 100);

        $handler->handle($this->record(Level::Error, LokiPushHandler::CLIENT_ERRORS_CHANNEL, 'render blew up'));
        $handler->flush();

        /** @var array{body: string} $seen */
        $body = json_decode($seen['body'], true, 512, JSON_THROW_ON_ERROR);
        /** @var array{streams: list<array{stream: array{source: string, channel: string}}>} $body */
        self::assertSame('frontend', $body['streams'][0]['stream']['source']);
        self::assertSame('client_errors', $body['streams'][0]['stream']['channel']);
    }

    public function testLabelsSourceBackendForEveryOtherChannel(): void
    {
        $seen = [];
        $http = new MockHttpClient(function (string $m, string $u, array $o) use (&$seen): MockResponse {
            $seen = $o;

            return new MockResponse('', ['http_code' => 204]);
        });
        $handler = new LokiPushHandler(new LokiClient($http, $this->endpoint()), 'sfr', 'prod', Level::Info, 100);

        $handler->handle($this->record(Level::Info, 'app', 'ordinary'));
        $handler->flush();

        /** @var array{body: string} $seen */
        $body = json_decode($seen['body'], true, 512, JSON_THROW_ON_ERROR);
        /** @var array{streams: list<array{stream: array{source: string}}>} $body */
        self::assertSame('backend', $body['streams'][0]['stream']['source']);
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
