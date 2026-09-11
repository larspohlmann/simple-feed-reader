<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\EventListener\RequestIdListener;
use App\Service\Logging\RequestIdProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

final class RequestIdListenerTest extends TestCase
{
    public function testMainRequestStartsAFreshId(): void
    {
        $provider = new RequestIdProvider();
        $first = $provider->current();
        $listener = new RequestIdListener($provider);

        $listener->onKernelRequest($this->requestEvent(HttpKernelInterface::MAIN_REQUEST));

        self::assertNotSame($first, $provider->current());
    }

    public function testSubRequestDoesNotChangeTheId(): void
    {
        $provider = new RequestIdProvider();
        $provider->set('01J000000000000000000TEST');
        $listener = new RequestIdListener($provider);

        $listener->onKernelRequest($this->requestEvent(HttpKernelInterface::SUB_REQUEST));

        self::assertSame('01J000000000000000000TEST', $provider->current());
    }

    public function testWorkerMessageReceivedStartsAFreshId(): void
    {
        $provider = new RequestIdProvider();
        $provider->set('01J000000000000000000TEST');
        $listener = new RequestIdListener($provider);

        $listener->onWorkerMessageReceived($this->workerMessageReceivedEvent());

        self::assertNotSame('01J000000000000000000TEST', $provider->current());
    }

    private function requestEvent(int $type): RequestEvent
    {
        $kernel = $this->createStub(HttpKernelInterface::class);

        return new RequestEvent($kernel, new Request(), $type);
    }

    private function workerMessageReceivedEvent(): WorkerMessageReceivedEvent
    {
        return new WorkerMessageReceivedEvent(new Envelope(new \stdClass()), 'async');
    }
}
