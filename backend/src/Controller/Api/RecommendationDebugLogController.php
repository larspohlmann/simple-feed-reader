<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\RecommendationDebugLogJson;
use App\Repository\RecommendationRunLogRepository;
use App\Service\Recommendation\RecommendationDebugLogView;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
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
        private RecommendationDebugLogView $view,
    ) {
    }

    /**
     * `?run=<id>` picks one of the retained runs (#401); without it the panel
     * gets the newest, which is what it asked for before the log kept more
     * than one.
     */
    #[Route('', name: 'api_recommendations_debug_log', methods: ['GET'])]
    public function list(#[CurrentUser] User $user, Request $request): JsonResponse
    {
        return new JsonResponse($this->view->forUser($user, $request->query->getInt('run')));
    }

    #[Route('/{id}', name: 'api_recommendations_debug_log_entry', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function entry(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $log = $this->logs->findOwned($id, $user)
            ?? throw new NotFoundHttpException('No such debug log entry.');

        return new JsonResponse(RecommendationDebugLogJson::detail($log));
    }
}
