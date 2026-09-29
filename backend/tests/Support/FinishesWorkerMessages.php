<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\EventListener\ResetServicesListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Worker;

/** What messenger:consume attaches at run time and Worker::run() dispatches after each handled message. */
trait FinishesWorkerMessages
{
    private function finishAMessage(): void
    {
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = self::getContainer()->get('event_dispatcher');
        /** @var ResetServicesListener $listener */
        $listener = self::getContainer()->get('messenger.listener.reset_services');
        $dispatcher->addSubscriber($listener);
        $worker = new Worker([], $this->createStub(MessageBusInterface::class), $dispatcher);

        $dispatcher->dispatch(new WorkerRunningEvent($worker, false));
    }
}
