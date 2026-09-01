<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\Admin\InstanceSettingsRequest;
use App\Http\Admin\InstanceSettingsJson;
use App\Service\Settings\InstanceSettings;
use App\Service\Settings\RelyingPartyChange;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin/settings')]
final readonly class AdminSettingsController
{
    public function __construct(
        private InstanceSettings $settings,
        private RelyingPartyChange $relyingPartyChange,
        private InstanceSettingsJson $settingsJson,
    ) {
    }

    #[Route('', name: 'api_admin_settings_get', methods: ['GET'])]
    public function get(): JsonResponse
    {
        return new JsonResponse($this->settingsJson->current());
    }

    #[Route('', name: 'api_admin_settings_update', methods: ['PUT'])]
    public function update(#[MapRequestPayload] InstanceSettingsRequest $request): JsonResponse
    {
        $this->relyingPartyChange->guardAndInvalidatePasskeysIfChanged($request);
        $this->settings->update($request->toUpdate());

        return new JsonResponse($this->settingsJson->current());
    }
}
