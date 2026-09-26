<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Repository\DatabaseHealthRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    public function __invoke(DatabaseHealthRepository $database): JsonResponse
    {
        try {
            $database->ping();
        } catch (\Throwable) {
            return new JsonResponse(
                ['status' => 'error', 'database' => 'unreachable'],
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        return new JsonResponse(['status' => 'ok']);
    }
}
