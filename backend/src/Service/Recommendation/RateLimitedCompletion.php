<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Service\Ai\ProviderConnection;
use App\Service\Recommendation\Exception\RecommendationRunRateLimitedException;
use Symfony\Component\Clock\ClockInterface;

/**
 * Wraps the concurrent chat client with the run's rate-limit policy (#947). For
 * a blocking plan (the worker) it waits and re-fires only the still-rate-limited
 * calls, up to the plan's retries and total-wait budget; a call retried to
 * exhaustion keeps its RetryableProviderException. For a deferring plan (poll,
 * sweep) it never waits and returns the deferral for the advancer to record.
 *
 * It re-fires the limited subset rather than the whole wave, so a paid provider
 * is not re-billed for the calls that already answered.
 */
final readonly class RateLimitedCompletion
{
    public function __construct(
        private ChatCompletionClient $chat,
        private ClockInterface $clock,
    ) {
    }

    public function complete(
        ProviderConnection $connection,
        CompletionRequest $request,
        CompletionStreamObserver $observer,
        RetryPlan $plan,
    ): string {
        $result = $this->completeMany($connection, [new ConcurrentCompletion($request, $observer)], $plan);

        if ($result->isDeferred()) {
            throw new RecommendationRunRateLimitedException($result->deferSeconds);
        }

        $outcome = $result->outcomes[0];
        if ($outcome->isFailure()) {
            throw $outcome->cause();
        }

        return $outcome->content();
    }

    /**
     * @param non-empty-list<ConcurrentCompletion> $calls
     */
    public function completeMany(ProviderConnection $connection, array $calls, RetryPlan $plan): RateLimitedResult
    {
        $outcomes = $this->chat->completeMany($connection, $calls);
        $observed = false;
        $waited = 0.0;

        // $retry === 0 is the first retry after the initial send above; the wait
        // it uses is BACKOFF[0], the "1 s" step.
        for ($retry = 0;; $retry++) {
            $pending = $this->retryablePositions($outcomes);
            if ([] === $pending) {
                return RateLimitedResult::completed($outcomes, $observed);
            }

            $observed = true;
            $wait = $plan->waitSecondsFor($retry, $this->retryAfterAcross($outcomes, $pending));

            if (!$plan->blocks()) {
                return RateLimitedResult::deferred($wait);
            }

            if ($retry >= $plan->maxRetries()) {
                return RateLimitedResult::completed($outcomes, true);
            }

            if ($waited + $wait > $plan->budgetSeconds()) {
                return RateLimitedResult::deferred($wait);
            }

            $this->clock->sleep($wait);
            $waited += $wait;
            $outcomes = $this->refire($connection, $calls, $outcomes, $pending);
        }
    }

    /**
     * @param list<CompletionOutcome> $outcomes
     *
     * @return list<int>
     */
    private function retryablePositions(array $outcomes): array
    {
        $positions = [];
        foreach ($outcomes as $position => $outcome) {
            if ($outcome->isRetryable()) {
                $positions[] = $position;
            }
        }

        return $positions;
    }

    /**
     * @param list<CompletionOutcome> $outcomes
     * @param list<int>               $pending
     */
    private function retryAfterAcross(array $outcomes, array $pending): ?int
    {
        $seconds = null;
        foreach ($pending as $position) {
            $hint = $outcomes[$position]->retryAfterSeconds();
            if (null !== $hint) {
                $seconds = null === $seconds ? $hint : max($seconds, $hint);
            }
        }

        return $seconds;
    }

    /**
     * @param non-empty-list<ConcurrentCompletion> $calls
     * @param list<CompletionOutcome>              $outcomes
     * @param non-empty-list<int>                  $pending
     *
     * @return list<CompletionOutcome>
     */
    private function refire(ProviderConnection $connection, array $calls, array $outcomes, array $pending): array
    {
        $subset = array_map(
            static fn (int $position): ConcurrentCompletion => $calls[$position],
            $pending,
        );
        $refired = $this->chat->completeMany($connection, $subset);

        foreach ($pending as $index => $position) {
            $outcomes[$position] = $refired[$index];
        }

        return array_values($outcomes);
    }
}
