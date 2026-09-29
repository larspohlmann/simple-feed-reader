<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Event\UserAwaitingApproval;
use Symfony\Component\EventDispatcher\EventDispatcher;

/** Holds an event dispatcher that records every UserAwaitingApproval it dispatches. */
final class AwaitingApprovalRecorder
{
    public readonly EventDispatcher $dispatcher;

    /** @var list<UserAwaitingApproval> */
    private array $events = [];

    public function __construct()
    {
        $this->dispatcher = new EventDispatcher();
        $this->dispatcher->addListener(
            UserAwaitingApproval::class,
            function (UserAwaitingApproval $event): void {
                $this->events[] = $event;
            },
        );
    }

    /** @return list<UserAwaitingApproval> */
    public function events(): array
    {
        return $this->events;
    }
}
