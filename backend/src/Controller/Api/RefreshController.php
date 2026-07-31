<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\SubscriptionRepository;
use App\Repository\TagRepository;
use App\Service\RateLimit\RateLimitGuard;
use App\Service\Refresh\RefreshRequest;
use App\Service\Refresh\RefreshRunner;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Runs one budgeted refresh slice over the caller's own feeds — or a single one
 * via `?feedId=` — and returns the tally as JSON. Always HTTP 200: the client
 * switches on the `status` field (busy → wait and retry; partial → keep
 * looping; completed → done; aborted → terminal error) and loops until
 * `remaining` reaches 0.
 */
final class RefreshController
{
    /**
     * Above BudgetedFeedQueue::SAFETY_MARGIN_SECONDS (10) so a call processes
     * more than a single feed, and below typical FastCGI limits.
     */
    private const int BUDGET_SECONDS = 25;

    public function __construct(
        private readonly RefreshRunner $refreshRunner,
        private readonly SubscriptionRepository $subscriptions,
        private readonly TagRepository $tags,
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

        $userId = (int) $user->getId();

        if (null !== $feedId) {
            // The user-facing per-feed path is the one that makes the
            // FeedRepository IDOR reachable, so ownership is checked here too
            // (defence in depth) — 404, not 403, to avoid confirming the feed
            // exists to someone who is not subscribed to it.
            if (!$this->subscriptions->existsForUserAndFeed($userId, $feedId)) {
                throw new NotFoundHttpException('No such subscription.');
            }
            $request = RefreshRequest::forUserFeed($userId, $feedId, self::BUDGET_SECONDS);
        } elseif (null !== $tag) {
            // Same IDOR guard as the per-feed path: 404 (not 403) when the tag is
            // unknown or belongs to someone else, so it stays scoped to the user.
            if (null === $this->tags->findOneOwnedBy($tag, $userId)) {
                throw new NotFoundHttpException('No such tag.');
            }
            $request = RefreshRequest::forUserTag($userId, $tag, self::BUDGET_SECONDS);
        } else {
            $request = RefreshRequest::forUser($userId, self::BUDGET_SECONDS);
        }

        return new JsonResponse($this->refreshRunner->run($request)->toArray());
    }
}
