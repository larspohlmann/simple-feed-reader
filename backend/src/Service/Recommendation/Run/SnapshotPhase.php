<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Service\Recommendation\Prompt\Model\CandidatePoolRequestModel;
use App\Service\Recommendation\Prompt\Model\PromptLineModel;
use App\Service\Recommendation\Prompt\RecommendationCandidateLoader;
use App\Service\Recommendation\Prompt\RecommendationHistoryLoader;
use App\Service\Recommendation\Prompt\RecommendationPromptBuilder;
use App\Service\Recommendation\Run\Model\RecommendationRunReportModel;
use App\Service\Recommendation\Run\Pass\TickContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/** Freezes a pending run's candidate pool into batches without a provider call; an empty pool completes at once. */
final readonly class SnapshotPhase
{
    public function __construct(
        private RecommendationCandidateLoader $candidateLoader,
        private RecommendationHistoryLoader $historyLoader,
        private RecommendationPromptBuilder $promptBuilder,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function advance(TickContext $tick): RecommendationRunReportModel
    {
        $run = $tick->run;
        $candidates = $this->candidatesFor($tick);

        if ([] === $candidates) {
            $run->snapshot([]);
            $run->complete($this->clock->now());
            $this->entityManager->flush();

            return RecommendationRunReportModel::fromRun($run);
        }

        $history = $this->historyLoader->load($tick->userId(), $tick->settings);
        $run->snapshot($this->promptBuilder->packBatches($candidates, $history, $tick->settings));
        $this->entityManager->flush();

        return RecommendationRunReportModel::fromRun($run);
    }

    /** @return list<PromptLineModel> */
    private function candidatesFor(TickContext $tick): array
    {
        $now = $this->clock->now();

        return $this->candidateLoader->load($tick->userId(), new CandidatePoolRequestModel(
            // P<N>D is calendar-day arithmetic: N x 24 h only because Kernel pins the process timezone to UTC.
            since: $now->sub(new \DateInterval(\sprintf('P%dD', $tick->settings->lookbackDays))),
            poolSize: $tick->settings->candidatePoolSize,
            orderSeed: (int) $now->getTimestamp(),
        ));
    }
}
