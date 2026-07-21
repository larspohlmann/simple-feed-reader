<?php

declare(strict_types=1);

namespace App\Tests\Support\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Test-only routes under /api, registered exclusively by the `when@test`
 * section of config/routes.yaml and living in tests/ (autoload-dev), so they
 * cannot reach dev or prod.
 *
 * Only the ROLE_ADMIN probe remains: the application has no admin endpoint yet
 * (the user queue arrives in a later task), so access_control's `^/api/admin/`
 * rule is otherwise unobservable. The plain authenticated probe has been
 * retired now that GET /api/me is a real protected route — the firewall tests
 * assert against that instead, which is strictly better because it pins the
 * behaviour of a route that actually ships.
 */
final class ProtectedProbeController
{
    #[Route('/api/admin/_probe', name: 'test_probe_admin', methods: ['GET'])]
    public function admin(): JsonResponse
    {
        return new JsonResponse(['ok' => 'admin']);
    }
}
