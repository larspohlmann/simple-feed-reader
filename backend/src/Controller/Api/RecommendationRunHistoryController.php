<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\RecommendationRunHistoryJson;
use App\Service\Clock\Model\ViewerTimeZoneModel;
use App\Service\Recommendation\Feed\Model\MonthWindowModel;
use App\Service\Recommendation\Feed\RecommendationRunHistory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * What every for-you run has cost this account. No rate limiter: scoped, indexed reads of the current user, with no
 * id in the route to forge.
 */
#[Route('/api/recommendations/runs/history')]
final readonly class RecommendationRunHistoryController
{
    public function __construct(private RecommendationRunHistory $history)
    {
    }

    #[Route('', name: 'api_recommendations_run_history', methods: ['GET'])]
    public function overview(#[CurrentUser] User $user, Request $request): JsonResponse
    {
        return new JsonResponse(RecommendationRunHistoryJson::overview($this->history->overview(
            $user,
            ViewerTimeZoneModel::of($request->query->get('tz')),
        )));
    }

    #[Route(
        '/{month}',
        name: 'api_recommendations_run_history_month',
        requirements: ['month' => '\d{4}-(?:0[1-9]|1[0-2])'],
        methods: ['GET'],
    )]
    public function month(string $month, #[CurrentUser] User $user, Request $request): JsonResponse
    {
        return new JsonResponse(RecommendationRunHistoryJson::monthPage($this->history->month(
            $user,
            MonthWindowModel::of($month, ViewerTimeZoneModel::of($request->query->get('tz'))),
            $request->query->getInt('before') ?: null,
        )));
    }
}
