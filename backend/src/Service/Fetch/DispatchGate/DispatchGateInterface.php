<?php

declare(strict_types=1);

namespace App\Service\Fetch\DispatchGate;

/**
 * Asked once per ticket, as its first request is about to go on the wire rather than when it is pulled: a parked ticket
 * may wait a while. A retry of a ticket already on the wire is never asked about.
 */
interface DispatchGateInterface
{
    public function admits(int|string $key): bool;
}
