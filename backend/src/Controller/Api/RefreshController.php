<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\RefreshJson;
use App\Service\RateLimit\RateLimitGuard;
use App\Service\Refresh\TrackedRefreshRunner;
use App\Service\Refresh\UserRefreshScope;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Runs one budgeted refresh slice over the caller's own feeds — or a single one
 * via `?feedId=` — and returns the tally as JSON. Always HTTP 200: the client
 * switches on the `status` field (busy → wait and retry; partial → keep
 * looping; completed → done; aborted → terminal error) and loops until
 * `remaining` reaches 0. `progress` is the run as a whole — every
 * slice of it — and is the only figure a client should render.
 */
final class RefreshController
{
    public function __construct(
        private readonly TrackedRefreshRunner $trackedRefreshRunner,
        private readonly UserRefreshScope $scope,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactoryInterface $refreshLimiter,
    ) {
    }

    #[Route('/api/refresh', name: 'api_refresh', methods: ['POST'])]
    public function __invoke(
        #[CurrentUser] User $user,
        #[MapQueryParameter] ?int $feedId = null,
        #[MapQueryParameter] ?int $tag = null,
    ): JsonResponse {
        $this->rateLimitGuard->enforceForUser($this->refreshLimiter, $user);

        $request = $this->scope->requestFor($user->requireId(), $feedId, $tag);

        return new JsonResponse(RefreshJson::slice($this->trackedRefreshRunner->run($request)));
    }
}
