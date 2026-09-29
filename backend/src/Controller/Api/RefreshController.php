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
 * One budgeted refresh slice over the caller's feeds, narrowed by `?feedId=` or `?tag=`. The client loops on
 * `status` (busy: wait and retry; partial: call again; completed: done; aborted: stop) until `remaining` is 0.
 */
final readonly class RefreshController
{
    public function __construct(
        private TrackedRefreshRunner $trackedRefreshRunner,
        private UserRefreshScope $scope,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $refreshLimiter,
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
