<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Service\Recommendation\Engine\RecommendationEngineResolver;
use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Pool\Model\CandidatePoolRequestModel;
use App\Service\Recommendation\Pool\RecommendationCandidateLoader;
use App\Service\Recommendation\Run\Model\RecommendationRunReportModel;
use App\Service\Recommendation\Run\Pass\TickContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Freezes a pending run's candidate pool into its engine's batches, and the stored profile into the run, without a
 * provider call; an empty pool completes at once.
 */
final readonly class SnapshotPhase
{
    public function __construct(
        private RecommendationCandidateLoader $candidateLoader,
        private RecommendationEngineResolver $engines,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function advance(TickContext $tick): RecommendationRunReportModel
    {
        $run = $tick->run;
        $candidates = $this->candidatesFor($tick);

        if ([] === $candidates) {
            $run->snapshot($tick->engineKind, []);
            $run->complete($this->clock->now());
            $this->entityManager->flush();

            return RecommendationRunReportModel::fromRun($run);
        }

        $run->freezeProfile($tick->settings->profileText);
        $run->snapshot(
            $tick->engineKind,
            $this->engines->engineOf($tick->engineKind)->packBatches($candidates, $tick),
        );
        $this->entityManager->flush();

        return RecommendationRunReportModel::fromRun($run);
    }

    /** @return list<ArticleLineModel> */
    private function candidatesFor(TickContext $tick): array
    {
        $now = $this->clock->now();

        return $this->candidateLoader->load($tick->userId(), new CandidatePoolRequestModel(
            // P<N>D is calendar-day arithmetic: N x 24 h only because Kernel pins the process timezone to UTC.
            since: $now->sub(new \DateInterval(\sprintf('P%dD', $tick->settings->poolLimits->lookbackDays))),
            poolSize: $tick->settings->poolLimits->candidatePoolSize,
            orderSeed: (int) $now->getTimestamp(),
        ));
    }
}
