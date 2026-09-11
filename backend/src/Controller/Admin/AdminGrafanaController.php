<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\Admin\GrafanaSettingsRequest;
use App\Service\Grafana\GrafanaSettings;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ROLE_ADMIN is enforced by the `^/api/admin/` prefix rule in security.yaml,
 * not by a per-action attribute here.
 */
#[Route('/api/admin/grafana')]
final readonly class AdminGrafanaController
{
    public function __construct(private GrafanaSettings $settings)
    {
    }

    #[Route('', name: 'api_admin_grafana_get', methods: ['GET'])]
    public function get(): JsonResponse
    {
        return new JsonResponse($this->settings->view());
    }

    #[Route('', name: 'api_admin_grafana_update', methods: ['PUT'])]
    public function update(#[MapRequestPayload] GrafanaSettingsRequest $request): JsonResponse
    {
        $this->settings->update($request);

        return new JsonResponse($this->settings->view());
    }
}
