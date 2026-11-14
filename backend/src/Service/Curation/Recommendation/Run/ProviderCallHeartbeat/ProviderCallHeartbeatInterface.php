<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run\ProviderCallHeartbeat;

/** Told while a provider call is still alive, so a process others watch for liveness stays alive while it waits. */
interface ProviderCallHeartbeatInterface
{
    public function beat(): void;
}
