<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\EventListener\LokiFlushListener;
use App\Kernel;
use App\Service\Logging\Loki\DirectLokiSink;
use App\Service\Logging\Loki\LokiClient;
use App\Service\Logging\Loki\LokiEndpoint;
use App\Service\Logging\Loki\LokiPushHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * The direct-invocation tests below prove the listener's own logic, but
 * nothing in them proves Symfony's container actually wires the
 * #[AsEventListener] attributes to the real dispatcher for all three events
 * — the same gap DeferredMailFlushListenerTest closes for its sibling
 * listener. The *EventDrainsTheBufferThroughTheRealDispatcher tests below
 * close it here: they drive the real kernel/dispatcher and observe flush()'s
 * real side effect on the container-built LokiPushHandler, not a hand-built
 * stand-in. No Loki URL is configured in the test env, so the push itself
 * never reaches the network — but LokiPushHandler::flush() drains its buffer
 * unconditionally before that, so an emptied buffer is proof the listener
 * fired, not proof of delivery.
 */
final class LokiFlushListenerTest extends KernelTestCase
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

    public function testTerminateEventDrainsTheBufferThroughTheRealDispatcher(): void
    {
        $kernel = self::bootKernel();
        self::assertInstanceOf(Kernel::class, $kernel);

        $this->bufferOneRecordInTheContainerHandler();
        self::assertNotSame([], $this->containerHandlerBuffer(), 'must have something buffered before terminate');

        $kernel->terminate(new Request(), new Response());

        self::assertSame(
            [],
            $this->containerHandlerBuffer(),
            'kernel.terminate must reach LokiFlushListener and drain the buffer',
        );
    }

    public function testWorkerMessageHandledEventDrainsTheBufferThroughTheRealDispatcher(): void
    {
        self::bootKernel();
        $this->bufferOneRecordInTheContainerHandler();

        $this->realDispatcher()->dispatch(new WorkerMessageHandledEvent(new Envelope(new \stdClass()), 'receiver'));

        self::assertSame([], $this->containerHandlerBuffer());
    }

    public function testWorkerMessageFailedEventDrainsTheBufferThroughTheRealDispatcher(): void
    {
        self::bootKernel();
        $this->bufferOneRecordInTheContainerHandler();

        $this->realDispatcher()->dispatch(new WorkerMessageFailedEvent(
            new Envelope(new \stdClass()),
            'receiver',
            new \RuntimeException('boom'),
        ));

        self::assertSame([], $this->containerHandlerBuffer());
    }

    private function bufferOneRecordInTheContainerHandler(): void
    {
        $this->containerHandler()->handle(new LogRecord(new \DateTimeImmutable(), 'app', Level::Info, 'wired'));
    }

    private function containerHandler(): LokiPushHandler
    {
        /** @var LokiPushHandler $handler */
        $handler = self::getContainer()->get(LokiPushHandler::class);

        return $handler;
    }

    /** @return list<array{ts: string, line: string, labels: array<string, string>}> */
    private function containerHandlerBuffer(): array
    {
        $buffer = new \ReflectionProperty(LokiPushHandler::class, 'buffer');

        /** @var list<array{ts: string, line: string, labels: array<string, string>}> */
        return $buffer->getValue($this->containerHandler());
    }

    private function realDispatcher(): EventDispatcherInterface
    {
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);

        return $dispatcher;
    }

    private function bufferedListener(int &$posts): LokiFlushListener
    {
        $http = new MockHttpClient(function () use (&$posts): MockResponse {
            ++$posts;

            return new MockResponse('', ['http_code' => 204]);
        });
        $sink = new DirectLokiSink(new LokiClient($http, $this->endpoint()));
        $handler = new LokiPushHandler($sink, 'sfr', 'prod', Level::Info, 100);
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
