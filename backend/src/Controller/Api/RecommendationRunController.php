<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Exception\AiKeyUnreadableApiException;
use App\Exception\AiNotConfiguredApiException;
use App\Exception\AiProviderApiException;
use App\Exception\RecommendationRunActiveApiException;
use App\Http\RecommendationRunStatusJson;
use App\Service\Ai\Crypto\Exception\ApiKeyUnreadableException;
use App\Service\Ai\Exception\AiNotConfiguredException;
use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ModelNotOfferedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\RateLimit\RateLimitGuard;
use App\Service\Recommendation\Exception\RecommendationRunActiveException;
use App\Service\Recommendation\RecommendationForYouSummaryProvider;
use App\Service\Recommendation\RecommendationPollDriver;
use App\Service\Recommendation\RecommendationRunPurger;
use App\Service\Recommendation\RecommendationRunReport;
use App\Service\Recommendation\RecommendationRunStarter;
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
        private RecommendationForYouSummaryProvider $forYouSummaries,
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

        return new JsonResponse(RecommendationRunStatusJson::report($report, $this->forYouSummaries->forUser($user)));
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

        return new JsonResponse(RecommendationRunStatusJson::report($report, $this->forYouSummaries->forUser($user)));
    }

    #[Route('/current', name: 'api_recommendations_current', methods: ['GET'])]
    public function current(#[CurrentUser] User $user): JsonResponse
    {
        $report = $this->pollDriver->current($user);

        return new JsonResponse(RecommendationRunStatusJson::report($report, $this->forYouSummaries->forUser($user)));
    }

    #[Route('', name: 'api_recommendations_purge', methods: ['DELETE'])]
    public function purge(#[CurrentUser] User $user): JsonResponse
    {
        try {
            $this->purger->purge($user);
        } catch (RecommendationRunActiveException $e) {
            throw new RecommendationRunActiveApiException($e);
        }

        return new JsonResponse(
            RecommendationRunStatusJson::report(
                RecommendationRunReport::none(),
                $this->forYouSummaries->forUser($user),
            ),
        );
    }
}
