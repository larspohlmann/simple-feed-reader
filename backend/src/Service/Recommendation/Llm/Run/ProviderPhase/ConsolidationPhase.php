<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Llm\Run\ProviderPhase;

use App\Entity\RecommendationRun;
use App\Service\Recommendation\Llm\Run\Model\ConsolidationOutcomeModel;
use App\Service\Recommendation\Llm\Run\RecommendationConsolidationResolver;
use App\Service\Recommendation\Run\Model\RecommendationRunReportModel;
use App\Service\Recommendation\Run\Pass\TickContext;
use App\Service\Recommendation\Run\RecommendationRunFinalizer;
use Doctrine\ORM\EntityManagerInterface;

/** Finalizes the consolidated list; an unusable reply is retried, then the run completes with its fallback ranking. */
final readonly class ConsolidationPhase implements ProviderPhaseInterface
{
    public function __construct(
        private RecommendationConsolidationResolver $resolver,
        private RecommendationRunFinalizer $finalizer,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function advance(TickContext $tick): RecommendationRunReportModel
    {
        $run = $tick->run;
        $outcome = $this->resolver->resolve($tick);

        if (!$outcome->usable) {
            return $this->retryOrFallBack($run, $outcome);
        }

        return $this->finalizer->finalize($run, $outcome->ranked);
    }

    private function retryOrFallBack(
        RecommendationRun $run,
        ConsolidationOutcomeModel $outcome,
    ): RecommendationRunReportModel {
        $run->getRunningCallAttempts()->recordInvalidReply($outcome->requireUnusableReply());
        if ($run->getProgress()->attemptsExhausted) {
            return $this->finalizer->finalize($run, $outcome->requireFallbackRanking());
        }
        $this->entityManager->flush();

        return RecommendationRunReportModel::fromRun($run);
    }
}
