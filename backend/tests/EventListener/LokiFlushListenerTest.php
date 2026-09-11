<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\EventListener\LokiFlushListener;
use App\Service\Logging\Loki\LokiClient;
use App\Service\Logging\Loki\LokiEndpoint;
use App\Service\Logging\Loki\LokiPushHandler;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class LokiFlushListenerTest extends TestCase
{
    public function testTerminateFlushesTheHandler(): void
    {
        $posts = 0;
        $listener = $this->bufferedListener($posts);

        $listener->onKernelTerminate();

        self::assertSame(1, $posts);
    }

    public function testWorkerMessageHandledFlushesTheHandler(): void
    {
        $posts = 0;
        $listener = $this->bufferedListener($posts);

        $listener->onWorkerMessageHandled();

        self::assertSame(1, $posts);
    }

    public function testWorkerMessageFailedFlushesTheHandler(): void
    {
        $posts = 0;
        $listener = $this->bufferedListener($posts);

        $listener->onWorkerMessageFailed();

        self::assertSame(1, $posts);
    }

    private function bufferedListener(int &$posts): LokiFlushListener
    {
        $http = new MockHttpClient(function () use (&$posts): MockResponse {
            ++$posts;

            return new MockResponse('', ['http_code' => 204]);
        });
        $handler = new LokiPushHandler(new LokiClient($http, $this->endpoint()), 'sfr', 'prod', Level::Info, 100);
        $handler->handle(new LogRecord(new \DateTimeImmutable(), 'app', Level::Info, 'buffered'));

        return new LokiFlushListener($handler);
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
