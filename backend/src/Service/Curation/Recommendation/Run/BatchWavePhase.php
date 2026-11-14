<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Run;

use App\Entity\RecommendationRun;
use App\Service\Curation\Ai\Exception\ProviderRateLimitedException;
use App\Service\Curation\Ai\Exception\RetryableProviderException;
use App\Service\Curation\Recommendation\Run\Model\BatchWaveResultModel;
use App\Service\Curation\Recommendation\Run\Model\RecommendationRunReportModel;
use App\Service\Curation\Recommendation\Run\Model\TickDriver;
use App\Service\Curation\Recommendation\Run\Model\WaveBatchModel;
use App\Service\Curation\Recommendation\Run\Pass\TickContext;
use Doctrine\ORM\EntityManagerInterface;

/** The batch phase every engine shares; the engine supplies only the wave itself. */
final readonly class BatchWavePhase
{
    /** A poll or sweep tick is a web request: its wave stays this small whatever the connection allows. */
    public const int POLL_MAX_CONCURRENCY = 2;

    public function __construct(
        private WaveBatchLoader $batches,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** @param \Closure(list<WaveBatchModel>): BatchWaveResultModel $resolveWave the engine's wave over these batches */
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
     * @param \Closure(list<WaveBatchModel>): BatchWaveResultModel $resolveWave
     */
    private function resolveWave(TickContext $tick, \Closure $resolveWave): BatchWaveResultModel
    {
        $batches = $this->batches->next($tick, $this->waveSize($tick));

        try {
            $result = $resolveWave($batches);
        } catch (ProviderRateLimitedException | RetryableProviderException $exception) {
            $this->halveConcurrency($tick);

            throw $exception;
        }

        if ($result->rateLimitObserved) {
            $this->halveConcurrency($tick);
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
            \count($tick->run->getCandidateBatches()) - $progress->nextBatchIndex,
        );
    }

    /** Never below 1: a directly stored concurrency ≤ 0 would wedge the run. */
    private function effectiveCap(TickContext $tick): int
    {
        $runTuning = $tick->connection->getRunTuning();
        $configuredCap = $runTuning->cappedBatchConcurrency();
        $driverCap = TickDriver::Worker === $tick->driver
            ? $configuredCap
            : min($runTuning->batchConcurrency(), self::POLL_MAX_CONCURRENCY);

        return max(1, min($driverCap, $tick->run->getWaveConcurrencyCap($configuredCap)));
    }

    private function halveConcurrency(TickContext $tick): void
    {
        $configuredCap = $tick->connection->getRunTuning()->cappedBatchConcurrency();
        $tick->run->getRunningThrottle()->reduceConcurrency($configuredCap);
    }
}
