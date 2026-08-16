<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\RecommendationRunHistoryJson;
use App\Repository\RecommendationRunHistoryRepository;
use App\Service\Recommendation\MonthWindow;
use App\Service\Recommendation\ViewerTimeZone;
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
 *
 * Temporary bridge (#409 Task 3): historyForUser() moved off the repository
 * in favour of pageForMonth(), which is month-scoped and reads one row past
 * the cap so a caller can tell there is another page. Task 5 replaces this
 * action with the real month-section route; until then this asks for the
 * current UTC calendar month and trims the extra row itself, so the response
 * shape stays exactly what it was.
 */
#[Route('/api/recommendations/runs/history')]
final readonly class RecommendationRunHistoryController
{
    public function __construct(private RecommendationRunHistoryRepository $runs)
    {
    }

    #[Route('', name: 'api_recommendations_run_history', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        $currentMonth = MonthWindow::of(
            (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m'),
            ViewerTimeZone::of(null),
        );
        $rows = $this->runs->pageForMonth($user, $currentMonth, null);

        return new JsonResponse(RecommendationRunHistoryJson::payload(
            \array_slice($rows, 0, RecommendationRunHistoryRepository::HISTORY_LIMIT),
            $this->runs->totalCostNanoCredits($user),
        ));
    }
}
