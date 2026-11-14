<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Service\Ai\Model\RateLimitedResultModel;
use App\Service\Ai\Model\RetryPlanModel;
use App\Service\Ai\RateLimitedOutcome\RateLimitedOutcomeInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Applies a RetryPlanModel to rate-limited calls: a blocking plan waits and re-sends only the still-limited calls, so
 * a paid provider is not re-billed for those that answered; a deferring plan never waits, it returns the deferral.
 */
final readonly class RateLimitedCalls
{
    public function __construct(private ClockInterface $clock)
    {
    }

    /**
     * @template TCall
     * @template TOutcome of RateLimitedOutcomeInterface
     *
     * @param non-empty-list<TCall>                           $calls
     * @param \Closure(non-empty-list<TCall>): list<TOutcome> $send  one outcome per call, aligned by index
     *
     * @return RateLimitedResultModel<TOutcome>
     */
    public function send(array $calls, \Closure $send, RetryPlanModel $plan): RateLimitedResultModel
    {
        $outcomes = $send($calls);
        $observed = false;
        $waited = 0.0;

        for ($retry = 0;; $retry++) {
            $pending = self::retryablePositions($outcomes);
            if ([] === $pending) {
                return RateLimitedResultModel::completed($outcomes, $observed);
            }

            $observed = true;
            $wait = $plan->waitSecondsFor($retry, self::retryAfterAcross($outcomes, $pending));

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
            $outcomes = self::resent($send, $calls, $outcomes, $pending);
        }
    }

    /**
     * @param list<RateLimitedOutcomeInterface> $outcomes
     *
     * @return list<int>
     */
    private static function retryablePositions(array $outcomes): array
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
     * @param list<RateLimitedOutcomeInterface> $outcomes
     * @param list<int>                         $pending
     */
    private static function retryAfterAcross(array $outcomes, array $pending): ?int
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
     * @template TCall
     * @template TOutcome of RateLimitedOutcomeInterface
     *
     * @param \Closure(non-empty-list<TCall>): list<TOutcome> $send
     * @param non-empty-list<TCall>                           $calls
     * @param list<TOutcome>                                  $outcomes
     * @param non-empty-list<int>                             $pending
     *
     * @return list<TOutcome>
     */
    private static function resent(\Closure $send, array $calls, array $outcomes, array $pending): array
    {
        // No return type on the arrow function: PHPStan infers TCall from $calls, which `mixed` would erase.
        $subset = array_map(static fn (int $position) => $calls[$position], $pending);
        $answers = $send($subset);

        foreach ($pending as $index => $position) {
            $outcomes[$position] = $answers[$index];
        }

        return array_values($outcomes);
    }
}
