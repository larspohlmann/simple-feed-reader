<?php

declare(strict_types=1);

namespace App\DependencyInjection;

/** Marks a service whose mutable state outlives a Messenger message on purpose; the reason says why. */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class ProcessLifetimeState
{
    public function __construct(public string $reason)
    {
    }
}
