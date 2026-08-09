<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\RecommendationDebugLogJson;
use App\Repository\RecommendationRunLogRepository;
use App\Repository\RecommendationRunRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Read side of the recommendation debug view (#309). Both routes are plain
 * reads with no limiter — the ~2 s panel poll is the whole point, same
 * stance as the run status `current` route.
 */
#[Route('/api/recommendations/runs/debug-log')]
final readonly class RecommendationDebugLogController
{
    public function __construct(
        private RecommendationRunLogRepository $logs,
        private RecommendationRunRepository $runs,
    ) {
    }

    #[Route('', name: 'api_recommendations_debug_log', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(RecommendationDebugLogJson::list(
            $this->logs->listForUser($user),
            $this->logs->streamingTextForUser($user),
            $this->runs->findLatestForUser($user),
        ));
    }

    #[Route('/{id}', name: 'api_recommendations_debug_log_entry', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function entry(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $log = $this->logs->findOwned($id, $user)
            ?? throw new NotFoundHttpException('No such debug log entry.');

        return new JsonResponse(RecommendationDebugLogJson::detail($log));
    }
}
