<?php

declare(strict_types=1);

namespace App\Service\Ai\Completion;

use App\Service\Ai\Completion\ChatCompletionClient\ChatCompletionClientInterface;
use App\Service\Ai\Completion\CompletionStreamObserver\CompletionStreamObserverInterface;
use App\Service\Ai\Completion\Model\CompletionOutcomeModel;
use App\Service\Ai\Completion\Model\CompletionRequestModel;
use App\Service\Ai\Completion\Model\RateLimitedResultModel;
use App\Service\Ai\Completion\Model\RetryPlanModel;
use App\Service\Ai\Exception\ProviderRateLimitedException;
use App\Service\Ai\Model\ProviderConnectionModel;
use Symfony\Component\Clock\ClockInterface;

/**
 * Applies a RetryPlanModel to rate-limited calls (#947): a blocking plan waits and re-fires only the still-limited
 * calls, so a paid provider is not re-billed for those that answered; a deferring plan never waits, it returns the
 * deferral.
 */
final readonly class RateLimitedCompletion
{
    public function __construct(
        private ChatCompletionClientInterface $chat,
        private ClockInterface $clock,
    ) {
    }

    public function complete(
        ProviderConnectionModel $connection,
        CompletionRequestModel $request,
        CompletionStreamObserverInterface $observer,
        RetryPlanModel $plan,
    ): string {
        $result = $this->completeMany($connection, [new ConcurrentCompletion($request, $observer)], $plan);

        if ($result->isDeferred()) {
            throw new ProviderRateLimitedException($result->deferSeconds);
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
    public function completeMany(
        ProviderConnectionModel $connection,
        array $calls,
        RetryPlanModel $plan,
    ): RateLimitedResultModel {
        $outcomes = $this->chat->completeMany($connection, $calls);
        $observed = false;
        $waited = 0.0;

        // $retry === 0 is the first retry after the initial send above; the wait
        // it uses is BACKOFF[0], the "1 s" step.
        for ($retry = 0;; $retry++) {
            $pending = $this->retryablePositions($outcomes);
            if ([] === $pending) {
                return RateLimitedResultModel::completed($outcomes, $observed);
            }

            $observed = true;
            $wait = $plan->waitSecondsFor($retry, $this->retryAfterAcross($outcomes, $pending));

            if (!$plan->blocks()) {
                return RateLimitedResultModel::deferred($wait);
            }

            if ($retry >= $plan->maxRetries()) {
                return RateLimitedResultModel::completed($outcomes, true);
            }

            if ($waited + $wait > $plan->budgetSeconds()) {
                return RateLimitedResultModel::deferred($wait);
            }

            $this->clock->sleep($wait);
            $waited += $wait;
            $outcomes = $this->refire($connection, $calls, $outcomes, $pending);
        }
    }

    /**
     * @param list<CompletionOutcomeModel> $outcomes
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
     * @param list<CompletionOutcomeModel> $outcomes
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
     * @param list<CompletionOutcomeModel>         $outcomes
     * @param non-empty-list<int>                  $pending
     *
     * @return list<CompletionOutcomeModel>
     */
    private function refire(ProviderConnectionModel $connection, array $calls, array $outcomes, array $pending): array
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
