<?php

declare(strict_types=1);

namespace App\Service\Maintenance;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * Authorises a machine-facing maintenance request by a shared token, in constant
 * time. Used instead of JWT: the scheduled pinger has no user to sign in as.
 */
final readonly class MaintenanceTokenGuard
{
    public function __construct(
        #[Autowire('%env(MAINTENANCE_TOKEN)%')]
        private string $configuredToken,
    ) {
    }

    /**
     * Prefer the header: a query-string token ends up in Apache access logs,
     * proxy logs, and Referer headers. The query parameter stays supported for
     * callers that cannot set headers, but the scheduled pinger should use the
     * header form.
     */
    public function isAuthorized(Request $request): bool
    {
        // An empty configured token denies everything: an unset MAINTENANCE_TOKEN
        // env var must never open the endpoint.
        if ($this->configuredToken === '') {
            return false;
        }

        $provided = $request->headers->get('X-Maintenance-Token')
            ?? $request->query->get('token');

        // hash_equals, not ===: the comparison stays constant-time.
        return \is_string($provided) && hash_equals($this->configuredToken, $provided);
    }
}
