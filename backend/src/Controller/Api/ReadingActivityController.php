<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\ReadingActivityJson;
use App\Service\Clock\Model\ViewerTimeZoneModel;
use App\Service\Reading\ReadingActivityCounter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Daily read counts for the last thirty days, bucketed in the viewer's zone. No rate limiter: one bounded query,
 * scoped to the current user, with no id in the route to forge.
 */
#[Route('/api/reading')]
final readonly class ReadingActivityController
{
    public function __construct(private ReadingActivityCounter $activity)
    {
    }

    #[Route('/activity', name: 'api_reading_activity', methods: ['GET'])]
    public function activity(#[CurrentUser] User $user, Request $request): JsonResponse
    {
        return new JsonResponse(ReadingActivityJson::of($this->activity->daily(
            $user,
            ViewerTimeZoneModel::of($request->query->get('tz')),
        )));
    }
}
