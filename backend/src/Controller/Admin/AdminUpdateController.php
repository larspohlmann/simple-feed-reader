<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Http\Admin\SelfUpdateStatusJson;
use App\Service\SelfUpdate\SelfUpdater;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/admin/update')]
final readonly class AdminUpdateController
{
    public function __construct(private SelfUpdater $updater)
    {
    }

    #[Route('', name: 'api_admin_update_status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        return new JsonResponse(SelfUpdateStatusJson::of($this->updater->status()));
    }

    #[Route('', name: 'api_admin_update_request', methods: ['POST'])]
    public function request(#[CurrentUser] User $admin): JsonResponse
    {
        $this->updater->request($admin->getEmail());

        return new JsonResponse(SelfUpdateStatusJson::of($this->updater->status()), Response::HTTP_ACCEPTED);
    }
}
