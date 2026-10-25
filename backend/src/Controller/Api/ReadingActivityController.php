<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\ReadingActivityJson;
use App\Service\Reading\ReadingActivityCounter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Daily new and read counts for the last thirty UTC days. No rate limiter: bounded queries, scoped to the current
 * user, with no id in the route to forge.
 */
#[Route('/api/reading')]
final readonly class ReadingActivityController
{
    public function __construct(private ReadingActivityCounter $activity)
    {
    }

    #[Route('/activity', name: 'api_reading_activity', methods: ['GET'])]
    public function activity(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(ReadingActivityJson::of($this->activity->activity($user)));
    }
}
