<?php

declare(strict_types=1);

namespace App\Service\Ai;

/**
 * Everything a completion call needs about the endpoint it is talking to: the
 * address and key that open it, and how long it is allowed to take.
 *
 * The two travel together because they are decided together, from one stored
 * configuration. Passing the timeouts alongside the credentials instead would
 * have widened every signature between the configurator and the transport for
 * a value none of them reads — the tramp-data shape phptramp gates — and made
 * it possible to call one connection's endpoint with another's patience.
 */
final readonly class ProviderConnection
{
    public function __construct(
        public ProviderCredentials $credentials,
        public ProviderTimeouts $timeouts,
    ) {
    }
}
