<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\RecommendationRun;
use App\Service\Ai\Exception\ProviderRateLimitedException;
use App\Service\Ai\Exception\RetryableProviderException;
use App\Service\Recommendation\Run\Model\BatchWaveResultModel;
use App\Service\Recommendation\Run\Model\RecommendationRunReportModel;
use App\Service\Recommendation\Run\Model\TickDriver;
use App\Service\Recommendation\Run\Pass\TickContext;
use Doctrine\ORM\EntityManagerInterface;

/** The batch phase every engine shares; the engine supplies only the wave itself. */
final readonly class BatchWavePhase
{
    /** A poll or sweep tick is a web request: its wave stays this small whatever the connection allows. */
    public const int POLL_MAX_CONCURRENCY = 2;

    public function __construct(
        private RecommendationWaveConcurrency $waveConcurrency,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** @param \Closure(int): BatchWaveResultModel $resolveWave the engine's wave over the next $waveSize batches */
    public function advance(TickContext $tick, \Closure $resolveWave): RecommendationRunReportModel
    {
        $run = $tick->run;
        $this->markFirstBatchBeforeCallingProvider($run);

        foreach ($this->resolveWave($tick, $resolveWave)->winners as $winners) {
            $run->recordBatchWinners($winners);
        }
        $this->entityManager->flush();

        return RecommendationRunReportModel::fromRun($run);
    }

    /**
     * A 429 anywhere in the wave halves the run's concurrency, whether the plan recovered or defers.
     *
     * @param \Closure(int): BatchWaveResultModel $resolveWave
     */
    private function resolveWave(TickContext $tick, \Closure $resolveWave): BatchWaveResultModel
    {
        try {
            $result = $resolveWave($this->waveSize($tick));
        } catch (ProviderRateLimitedException | RetryableProviderException $exception) {
            $this->waveConcurrency->halve($tick->run, $tick->connection);

            throw $exception;
        }

        if ($result->rateLimitObserved) {
            $this->waveConcurrency->halve($tick->run, $tick->connection);
        }

        return $result;
    }

    /** Flushed before the blocking call, so a status poll during it already sees the ETA may count down. */
    private function markFirstBatchBeforeCallingProvider(RecommendationRun $run): void
    {
        if ($run->hasFirstBatchStarted()) {
            return;
        }

        $run->markFirstBatchStarted();
        $this->entityManager->flush();
    }

    private function waveSize(TickContext $tick): int
    {
        $progress = $tick->run->getProgress();
        if (0 === $progress->nextBatchIndex) {
            return 1;
        }

        return min(
            $this->effectiveCap($tick),
            $this->waveConcurrency->cap($tick->run, $tick->connection),
            \count($tick->run->getCandidateBatches()) - $progress->nextBatchIndex,
        );
    }

    /** Never below 1, like the wave cap: a directly stored concurrency ≤ 0 would wedge the run. */
    private function effectiveCap(TickContext $tick): int
    {
        $connection = $tick->connection;
        $cap = TickDriver::Worker === $tick->driver
            ? $connection->cappedBatchConcurrency()
            : min($connection->batchConcurrency(), self::POLL_MAX_CONCURRENCY);

        return max(1, $cap);
    }
}
