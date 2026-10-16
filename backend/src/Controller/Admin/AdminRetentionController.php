<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\Admin\RetentionSettingsRequest;
use App\Http\Admin\RetentionSettingsJson;
use App\Http\FullReplacePayload;
use App\Service\Retention\RetentionSettings;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin/retention')]
final readonly class AdminRetentionController
{
    public function __construct(private RetentionSettings $settings)
    {
    }

    #[Route('', name: 'api_admin_retention_get', methods: ['GET'])]
    public function get(): JsonResponse
    {
        return new JsonResponse(RetentionSettingsJson::from($this->settings->current()));
    }

    #[Route('', name: 'api_admin_retention_update', methods: ['PUT'])]
    public function update(
        #[MapRequestPayload(serializationContext: FullReplacePayload::CONTEXT)] RetentionSettingsRequest $request,
    ): JsonResponse {
        $this->settings->update($request->toWindows());

        return new JsonResponse(RetentionSettingsJson::from($this->settings->current()));
    }
}
