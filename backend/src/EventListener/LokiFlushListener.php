<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\Logging\Loki\LokiPushHandler;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

#[AsEventListener(event: TerminateEvent::class, method: 'onKernelTerminate')]
#[AsEventListener(event: WorkerMessageHandledEvent::class, method: 'onWorkerMessageHandled')]
#[AsEventListener(event: WorkerMessageFailedEvent::class, method: 'onWorkerMessageFailed')]
final readonly class LokiFlushListener
{
    public function __construct(private LokiPushHandler $handler)
    {
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        $this->handler->flush();
    }

    public function onWorkerMessageHandled(WorkerMessageHandledEvent $event): void
    {
        $this->handler->flush();
    }

    public function onWorkerMessageFailed(WorkerMessageFailedEvent $event): void
    {
        $this->handler->flush();
    }
}
