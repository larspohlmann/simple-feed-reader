<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\RecommendationRunHistoryJson;
use App\Repository\RecommendationRunRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * What every for-you run has cost this account (#409): the newest runs with
 * their provider, duration and price, and the all-time total above them.
 *
 * Read-only and cheap — two indexed queries scoped to the current user — so
 * it carries no rate limiter, the same call the #309 debug log endpoint makes.
 * Ownership is enforced in the repository: every query filters on the
 * authenticated user, and there is no id in the route to forge.
 *
 * Its own controller rather than a seventh action on RecommendationRunController:
 * that class is about driving a run, and reading a spending record is not that.
 */
#[Route('/api/recommendations/runs/history')]
final readonly class RecommendationRunHistoryController
{
    public function __construct(private RecommendationRunRepository $runs)
    {
    }

    #[Route('', name: 'api_recommendations_run_history', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(RecommendationRunHistoryJson::payload(
            $this->runs->historyForUser($user),
            $this->runs->totalCostNanoCredits($user),
        ));
    }
}
