<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\RateLimit\RateLimitGuard;
use App\Service\Recommendation\RecommendationPollDriver;
use App\Service\Recommendation\RecommendationRunCanceller;
use App\Service\Recommendation\RecommendationRunPurger;
use App\Service\Recommendation\RecommendationRunReport;
use App\Service\Recommendation\RecommendationRunStarter;
use App\Service\Recommendation\RecommendationRunStatusPayload;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The poll loop the client drives. `current` is a plain read with no limiter. Starting a run commits outbound
 * spend; ticking is the progress loop and must stay generous enough never to throttle a long run (#308).
 */
#[Route('/api/recommendations/runs')]
final readonly class RecommendationRunController
{
    public function __construct(
        private RecommendationRunStarter $starter,
        private RecommendationPollDriver $pollDriver,
        private RecommendationRunPurger $purger,
        private RecommendationRunCanceller $canceller,
        private RecommendationRunStatusPayload $statusPayload,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $aiRecommendationsLimiter,
        private RateLimiterFactoryInterface $aiRecommendationStartsLimiter,
    ) {
    }

    #[Route('', name: 'api_recommendations_start', methods: ['POST'])]
    public function start(#[CurrentUser] User $user): JsonResponse
    {
        $this->rateLimitGuard->enforceForUser($this->aiRecommendationStartsLimiter, $user);

        return new JsonResponse($this->statusPayload->forReport($this->starter->start($user), $user));
    }

    /** Resumes the latest failed run; it shares the start limiter because it commits the same outbound spend. */
    #[Route('/resume', name: 'api_recommendations_resume', methods: ['POST'])]
    public function resume(#[CurrentUser] User $user): JsonResponse
    {
        $this->rateLimitGuard->enforceForUser($this->aiRecommendationStartsLimiter, $user);

        return new JsonResponse($this->statusPayload->forReport($this->starter->resume($user), $user));
    }

    #[Route('/tick', name: 'api_recommendations_tick', methods: ['POST'])]
    public function tick(#[CurrentUser] User $user): JsonResponse
    {
        $this->rateLimitGuard->enforceForUser($this->aiRecommendationsLimiter, $user);

        return new JsonResponse($this->statusPayload->forReport($this->pollDriver->poll($user), $user));
    }

    #[Route('/current', name: 'api_recommendations_current', methods: ['GET'])]
    public function current(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse($this->statusPayload->forReport($this->pollDriver->current($user), $user));
    }

    /** No limiter: stopping only reduces work, and throttling the way out of a spending run is backwards. */
    #[Route('/stop', name: 'api_recommendations_stop', methods: ['POST'])]
    public function stop(#[CurrentUser] User $user): JsonResponse
    {
        $this->canceller->cancel($user);

        return new JsonResponse($this->statusPayload->forReport($this->pollDriver->current($user), $user));
    }

    #[Route('', name: 'api_recommendations_purge', methods: ['DELETE'])]
    public function purge(#[CurrentUser] User $user): JsonResponse
    {
        $this->purger->purge($user);

        return new JsonResponse($this->statusPayload->forReport(RecommendationRunReport::none(), $user));
    }
}
