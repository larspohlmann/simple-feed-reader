<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\Admin\MailSettingsRequest;
use App\Service\Mail\Settings\MailConnectionTester;
use App\Service\Mail\Settings\MailSettings;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ROLE_ADMIN is enforced by the `^/api/admin/` prefix rule in security.yaml,
 * not by a per-action attribute here.
 */
#[Route('/api/admin/mail')]
final readonly class AdminMailController
{
    public function __construct(private MailSettings $settings)
    {
    }

    #[Route('', name: 'api_admin_mail_get', methods: ['GET'])]
    public function get(): JsonResponse
    {
        return new JsonResponse($this->settings->view());
    }

    #[Route('', name: 'api_admin_mail_update', methods: ['PUT'])]
    public function update(#[MapRequestPayload] MailSettingsRequest $request): JsonResponse
    {
        $this->settings->update($request);

        return new JsonResponse($this->settings->view());
    }

    #[Route('/test', name: 'api_admin_mail_test', methods: ['POST'])]
    public function test(MailConnectionTester $tester): JsonResponse
    {
        return new JsonResponse($tester->test()->toArray());
    }

    #[Route('/reset', name: 'api_admin_mail_reset', methods: ['POST'])]
    public function reset(): JsonResponse
    {
        $this->settings->resetToEnvironment();

        return new JsonResponse($this->settings->view());
    }
}
