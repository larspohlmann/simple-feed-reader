<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\Reading\ReadingActivityView;
use App\Service\Recommendation\ViewerTimeZone;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * How much the account has read lately (#896): one count per day for the last
 * thirty days, bucketed in the viewer's timezone, for the About page's reading
 * chart.
 *
 * Read-only and cheap — one scoped, bounded query against the current user — so
 * it carries no rate limiter, the same call RecommendationRunHistoryController
 * makes. Ownership is enforced in the view and repository: every query filters
 * on the authenticated user, and there is no id in the route to forge.
 */
#[Route('/api/reading')]
final readonly class ReadingActivityController
{
    public function __construct(private ReadingActivityView $view)
    {
    }

    #[Route('/activity', name: 'api_reading_activity', methods: ['GET'])]
    public function activity(#[CurrentUser] User $user, Request $request): JsonResponse
    {
        return new JsonResponse($this->view->daily(
            $user,
            ViewerTimeZone::of($request->query->get('tz')),
        ));
    }
}
