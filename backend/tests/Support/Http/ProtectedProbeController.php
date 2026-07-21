<?php

declare(strict_types=1);

namespace App\Tests\Support\Http;

use App\Entity\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Test-only routes under /api, registered exclusively by the `when@test`
 * section of config/routes.yaml and living in tests/ (autoload-dev), so they
 * cannot reach dev or prod.
 *
 * They exist because the firewall behaviour that most needs pinning — JWT
 * rejection shapes, ROLE_ADMIN enforcement, and revocation taking effect on the
 * next request — is only observable through a route the `api` firewall actually
 * protects, and the real ones arrive in later tasks.
 */
final class ProtectedProbeController
{
    #[Route('/api/_probe/protected', name: 'test_probe_protected', methods: ['GET'])]
    public function protected(#[CurrentUser] ?User $user): JsonResponse
    {
        return new JsonResponse(['email' => $user?->getUserIdentifier()]);
    }

    #[Route('/api/admin/_probe', name: 'test_probe_admin', methods: ['GET'])]
    public function admin(): JsonResponse
    {
        return new JsonResponse(['ok' => 'admin']);
    }
}
