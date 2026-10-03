<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run\Pass;

use App\Service\Recommendation\Run\ProviderCallHeartbeat\TickLockKeepalive;
use Symfony\Component\Lock\LockInterface;

/** One tick's hold on the per-user lock. */
final readonly class HeldTickLock
{
    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(private LockInterface $lock, private TickLockKeepalive $keepalive)
    {
    }

    /** Disarms the keepalive before the release, never after: a beat in between would refresh a lock on its way out. */
    public function release(): void
    {
        $this->keepalive->release();
        $this->lock->release();
    }
}
