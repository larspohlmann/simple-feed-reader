<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

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
     * The header is preferred: a query-string token lands in access logs, proxy logs and Referer headers. The query
     * form stays for callers that cannot set a header.
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

    /**
     * The standard rejection for an unauthorised maintenance call, or null when
     * the request is authorised. One source of truth for the forbidden shape,
     * so every maintenance action guards with the same two-line clause.
     */
    public function rejectionResponse(Request $request): ?JsonResponse
    {
        return $this->isAuthorized($request)
            ? null
            : new JsonResponse(['error' => 'forbidden'], Response::HTTP_FORBIDDEN);
    }
}
