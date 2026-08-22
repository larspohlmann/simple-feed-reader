<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\Admin\ProxySettingsRequest;
use App\Service\Proxy\ProxySettings;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ROLE_ADMIN is enforced by the `^/api/admin/` prefix rule in security.yaml,
 * not by a per-action attribute here.
 */
#[Route('/api/admin/proxy')]
final readonly class AdminProxyController
{
    public function __construct(private ProxySettings $settings)
    {
    }

    #[Route('', name: 'api_admin_proxy_get', methods: ['GET'])]
    public function get(): JsonResponse
    {
        return new JsonResponse($this->settings->view());
    }

    #[Route('', name: 'api_admin_proxy_update', methods: ['PUT'])]
    public function update(#[MapRequestPayload] ProxySettingsRequest $request): JsonResponse
    {
        $this->settings->update($request);

        return new JsonResponse($this->settings->view());
    }
}
