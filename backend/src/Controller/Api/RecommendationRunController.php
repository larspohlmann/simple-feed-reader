<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Exception\AiKeyUnreadableApiException;
use App\Exception\AiNotConfiguredApiException;
use App\Exception\AiProviderApiException;
use App\Exception\NoActiveRecommendationRunApiException;
use App\Exception\NoResumableRecommendationRunApiException;
use App\Exception\RecommendationRunActiveApiException;
use App\Service\Ai\Crypto\Exception\ApiKeyUnreadableException;
use App\Service\Ai\Exception\AiNotConfiguredException;
use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ModelNotOfferedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\RateLimit\RateLimitGuard;
use App\Service\Recommendation\Exception\NoActiveRecommendationRunException;
use App\Service\Recommendation\Exception\NoResumableRecommendationRunException;
use App\Service\Recommendation\Exception\RecommendationRunActiveException;
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
 * The poll loop the client drives: start a run, tick it forward, and read its
 * current state. `current` does no work and carries no limiter — it is a
 * plain read, safe to poll as often as the client likes.
 *
 * The two writes carry separate budgets: starting a run is what commits
 * outbound spend, while ticking is the progress loop and must stay generous
 * enough that a long run never throttles itself off (#308).
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

        try {
            $report = $this->starter->start($user);
        } catch (AiNotConfiguredException $e) {
            throw new AiNotConfiguredApiException($e);
        }

        return new JsonResponse($this->statusPayload->forReport($report, $user));
    }

    /**
     * Resumes the latest failed run at the batch that failed. Shares the start
     * limiter because it commits the same outbound spend a fresh run does, and
     * 409s when there is nothing failed to resume -- the client offers this
     * only after it has seen a failed run, so a miss is a stale click.
     */
    #[Route('/resume', name: 'api_recommendations_resume', methods: ['POST'])]
    public function resume(#[CurrentUser] User $user): JsonResponse
    {
        $this->rateLimitGuard->enforceForUser($this->aiRecommendationStartsLimiter, $user);

        try {
            $report = $this->starter->resume($user);
        } catch (AiNotConfiguredException $e) {
            throw new AiNotConfiguredApiException($e);
        } catch (NoResumableRecommendationRunException $e) {
            throw new NoResumableRecommendationRunApiException($e);
        }

        return new JsonResponse($this->statusPayload->forReport($report, $user));
    }

    #[Route('/tick', name: 'api_recommendations_tick', methods: ['POST'])]
    public function tick(#[CurrentUser] User $user): JsonResponse
    {
        $this->rateLimitGuard->enforceForUser($this->aiRecommendationsLimiter, $user);

        try {
            $report = $this->pollDriver->poll($user);
        } catch (AiNotConfiguredException $e) {
            throw new AiNotConfiguredApiException($e);
        } catch (ApiKeyUnreadableException $e) {
            throw new AiKeyUnreadableApiException($e);
        } catch (ProviderUnreachableException | CredentialsRejectedException | ModelNotOfferedException $e) {
            throw new AiProviderApiException($e->getMessage(), $e);
        }

        return new JsonResponse($this->statusPayload->forReport($report, $user));
    }

    #[Route('/current', name: 'api_recommendations_current', methods: ['GET'])]
    public function current(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse($this->statusPayload->forReport($this->pollDriver->current($user), $user));
    }

    /**
     * Stops the active run. Carries no limiter: it only ever reduces work, and
     * throttling the way out of a run that is spending money is the wrong way
     * round.
     */
    #[Route('/stop', name: 'api_recommendations_stop', methods: ['POST'])]
    public function stop(#[CurrentUser] User $user): JsonResponse
    {
        try {
            $this->canceller->cancel($user);
        } catch (NoActiveRecommendationRunException $e) {
            throw new NoActiveRecommendationRunApiException($e);
        }

        return new JsonResponse($this->statusPayload->forReport($this->pollDriver->current($user), $user));
    }

    #[Route('', name: 'api_recommendations_purge', methods: ['DELETE'])]
    public function purge(#[CurrentUser] User $user): JsonResponse
    {
        try {
            $this->purger->purge($user);
        } catch (RecommendationRunActiveException $e) {
            throw new RecommendationRunActiveApiException($e);
        }

        return new JsonResponse($this->statusPayload->forReport(RecommendationRunReport::none(), $user));
    }
}
