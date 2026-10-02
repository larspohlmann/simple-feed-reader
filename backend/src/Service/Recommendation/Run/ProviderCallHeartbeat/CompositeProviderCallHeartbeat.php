<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run\ProviderCallHeartbeat;

/**
 * One beat for two named members, keepalive first: a throwing member skips the rest, and a missed lock refresh risks
 * a stolen lock and a double-banked run while a missed liveness mark only delays a UI hint. Never make it a list.
 */
final readonly class CompositeProviderCallHeartbeat implements ProviderCallHeartbeatInterface
{
    public function __construct(
        private ProviderCallHeartbeatInterface $lockKeepalive,
        private ProviderCallHeartbeatInterface $workerLiveness,
    ) {
    }

    public function beat(): void
    {
        $this->lockKeepalive->beat();
        $this->workerLiveness->beat();
    }
}
