<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\Admin\GrafanaSettingsRequest;
use App\Http\Admin\GrafanaSettingsJson;
use App\Http\FullReplacePayload;
use App\Service\Grafana\GrafanaSettings;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin/grafana')]
final readonly class AdminGrafanaController
{
    public function __construct(private GrafanaSettings $settings)
    {
    }

    #[Route('', name: 'api_admin_grafana_get', methods: ['GET'])]
    public function get(): JsonResponse
    {
        return new JsonResponse(GrafanaSettingsJson::from($this->settings->overview()));
    }

    #[Route('', name: 'api_admin_grafana_update', methods: ['PUT'])]
    public function update(
        #[MapRequestPayload(serializationContext: FullReplacePayload::CONTEXT)] GrafanaSettingsRequest $request,
    ): JsonResponse {
        $this->settings->update($request->toUpdate());

        return new JsonResponse(GrafanaSettingsJson::from($this->settings->overview()));
    }
}
