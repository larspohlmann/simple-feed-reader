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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class LokiFlushListenerTest extends TestCase
{
    public function testTerminateFlushesTheHandler(): void
    {
        $posts = 0;
        $http = new MockHttpClient(function () use (&$posts): MockResponse {
            ++$posts;

            return new MockResponse('', ['http_code' => 204]);
        });
        $handler = new LokiPushHandler(new LokiClient($http, $this->endpoint()), 'sfr', 'prod', Level::Info, 100);
        $handler->handle(new LogRecord(new \DateTimeImmutable(), 'app', Level::Info, 'buffered'));
        $listener = new LokiFlushListener($handler);

        $listener->onKernelTerminate($this->terminateEvent());

        self::assertSame(1, $posts);
    }

    private function terminateEvent(): TerminateEvent
    {
        $kernel = $this->createStub(HttpKernelInterface::class);

        return new TerminateEvent($kernel, new Request(), new Response());
    }

    private function endpoint(): LokiEndpoint
    {
        return new class implements LokiEndpoint {
            public function pushUrl(): ?string
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
