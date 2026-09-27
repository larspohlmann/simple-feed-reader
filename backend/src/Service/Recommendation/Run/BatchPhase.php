<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\RecommendationRun;
use App\Service\Ai\Exception\ProviderRateLimitedException;
use App\Service\Ai\Exception\RetryableProviderException;
use Doctrine\ORM\EntityManagerInterface;

final readonly class BatchPhase implements ProviderPhase
{
    /** A poll or sweep tick is a web request: its wave stays this small whatever the connection allows (#344). */
    public const int POLL_MAX_CONCURRENCY = 2;

    public function __construct(
        private WaveContextLoader $waves,
        private RecommendationBatchWave $batchWave,
        private RecommendationWaveConcurrency $waveConcurrency,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function advance(TickContext $tick): RecommendationRunReport
    {
        $run = $tick->run;
        $this->markFirstBatchBeforeCallingProvider($run);

        foreach ($this->resolveWave($tick)->winners as $winners) {
            $run->recordBatchWinners($winners);
        }
        $this->entityManager->flush();

        return RecommendationRunReport::fromRun($run);
    }

    /** A 429 anywhere in the wave halves the run's concurrency, whether the plan recovered or defers (#947). */
    private function resolveWave(TickContext $tick): BatchWaveResult
    {
        try {
            $result = $this->batchWave->resolve($this->waves->load($tick, $this->waveSize($tick)));
        } catch (ProviderRateLimitedException | RetryableProviderException $e) {
            $this->waveConcurrency->halve($tick->run, $tick->connection);

            throw $e;
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

    /** Floors at 1 so a stored concurrency ≤ 0 cannot wedge the run (#344). */
    private function waveSize(TickContext $tick): int
    {
        $progress = $tick->run->progress();
        if (0 === $progress->nextBatchIndex) {
            return 1;
        }

        $concurrency = min($this->effectiveCap($tick), $this->waveConcurrency->cap($tick->run, $tick->connection));

        return min(
            max(1, $concurrency),
            \count($tick->run->getCandidateBatches()) - $progress->nextBatchIndex,
        );
    }

    private function effectiveCap(TickContext $tick): int
    {
        $connection = $tick->connection;

        return TickDriver::Worker === $tick->driver
            ? $connection->cappedBatchConcurrency()
            : min($connection->batchConcurrency(), self::POLL_MAX_CONCURRENCY);
    }
}
