<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\RecommendationDebugLogJson;
use App\Repository\RecommendationRunLogRepository;
use App\Service\Recommendation\Feed\RecommendationDebugLogLoader;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Plain reads with no rate limiter: the debug panel polls every ~2 s while a run is in progress.
 */
#[Route('/api/recommendations/runs/debug-log')]
final readonly class RecommendationDebugLogController
{
    public function __construct(
        private RecommendationRunLogRepository $logs,
        private RecommendationDebugLogLoader $debugLogs,
    ) {
    }

    /** `?run=<id>` picks one of the retained runs; without it, the newest. */
    #[Route('', name: 'api_recommendations_debug_log', methods: ['GET'])]
    public function list(#[CurrentUser] User $user, Request $request): JsonResponse
    {
        return new JsonResponse(RecommendationDebugLogJson::list(
            $this->debugLogs->forUser($user, $request->query->getInt('run')),
        ));
    }

    #[Route('/{id}', name: 'api_recommendations_debug_log_entry', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function entry(int $id, #[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(RecommendationDebugLogJson::detail($this->logs->getOneForUser($user, $id)));
    }
}
