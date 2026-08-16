<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\Recommendation\MonthWindow;
use App\Service\Recommendation\RecommendationRunHistoryView;
use App\Service\Recommendation\ViewerTimeZone;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * What every for-you run has cost this account (#409): an overview with the
 * all-time total, one summary per calendar month and the newest month's own
 * runs, plus a route to page further into any other month.
 *
 * Read-only and cheap — scoped, indexed queries against one user — so it
 * carries no rate limiter, the same call the #309 debug log endpoint makes.
 * Ownership is enforced in the repository: every query filters on the
 * authenticated user, and there is no id in the route to forge.
 *
 * Its own controller rather than a seventh action on RecommendationRunController:
 * that class is about driving a run, and reading a spending record is not that.
 */
#[Route('/api/recommendations/runs/history')]
final readonly class RecommendationRunHistoryController
{
    public function __construct(private RecommendationRunHistoryView $view)
    {
    }

    #[Route('', name: 'api_recommendations_run_history', methods: ['GET'])]
    public function overview(#[CurrentUser] User $user, Request $request): JsonResponse
    {
        return new JsonResponse($this->view->overview(
            $user,
            ViewerTimeZone::of($request->query->get('tz')),
        ));
    }

    #[Route(
        '/{month}',
        name: 'api_recommendations_run_history_month',
        requirements: ['month' => '\d{4}-(?:0[1-9]|1[0-2])'],
        methods: ['GET'],
    )]
    public function month(string $month, #[CurrentUser] User $user, Request $request): JsonResponse
    {
        return new JsonResponse($this->view->month(
            $user,
            MonthWindow::of($month, ViewerTimeZone::of($request->query->get('tz'))),
            $request->query->getInt('before') ?: null,
        ));
    }
}
