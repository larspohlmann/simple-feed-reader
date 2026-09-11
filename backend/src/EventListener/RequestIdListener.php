<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\Logging\RequestIdProvider;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

final readonly class RequestIdListener
{
    public function __construct(private RequestIdProvider $requestId)
    {
    }

    #[AsEventListener(event: RequestEvent::class, priority: 4096)]
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->requestId->startNew();
    }

    #[AsEventListener(event: WorkerMessageReceivedEvent::class)]
    public function onWorkerMessageReceived(WorkerMessageReceivedEvent $event): void
    {
        $this->requestId->startNew();
    }
}
