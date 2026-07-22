<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Exception\RateLimitedException;
use App\Repository\SubscriptionRepository;
use App\Service\Refresh\RefreshRequest;
use App\Service\Refresh\RefreshRunner;
use Psr\Clock\ClockInterface;
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
     * Above RefreshRunner::SAFETY_MARGIN_SECONDS (10) so a call processes more
     * than a single feed, and below typical FastCGI limits.
     */
    private const BUDGET_SECONDS = 25;

    public function __construct(
        private readonly RefreshRunner $refreshRunner,
        private readonly SubscriptionRepository $subscriptions,
        private readonly ClockInterface $clock,
        private readonly RateLimiterFactoryInterface $refreshLimiter,
    ) {
    }

    #[Route('/api/refresh', name: 'api_refresh', methods: ['POST'])]
    public function __invoke(
        #[CurrentUser] User $user,
        #[MapQueryParameter] ?int $feedId = null,
    ): JsonResponse {
        $this->enforceLimit($user);

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
        } else {
            $request = RefreshRequest::forUser($userId, self::BUDGET_SECONDS);
        }

        return new JsonResponse($this->refreshRunner->run($request)->toArray());
    }

    private function enforceLimit(User $user): void
    {
        $limit = $this->refreshLimiter->create('user-' . $user->getId())->consume();
        if ($limit->isAccepted()) {
            return;
        }

        // The listener turns this into 429 problem+json with a Retry-After
        // header. max(1, ...) guards against a just-elapsed retryAfter rendering
        // as "Retry-After: 0", which clients read as "now".
        throw new RateLimitedException(
            max(1, $limit->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp()),
        );
    }
}
