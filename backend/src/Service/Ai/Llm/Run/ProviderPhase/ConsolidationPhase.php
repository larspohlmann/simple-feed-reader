<?php

declare(strict_types=1);

namespace App\Service\Ai\Llm\Run\ProviderPhase;

use App\Service\Ai\Llm\Run\InvalidReplyRetry;
use App\Service\Ai\Llm\Run\RecommendationConsolidationResolver;
use App\Service\Recommendation\Run\Model\RecommendationRunReportModel;
use App\Service\Recommendation\Run\Pass\TickContext;
use App\Service\Recommendation\Run\RecommendationRunFinalizer;

/** Finalizes the consolidated list; an unusable reply is retried, then the run completes with its fallback ranking. */
final readonly class ConsolidationPhase implements ProviderPhaseInterface
{
    public function __construct(
        private RecommendationConsolidationResolver $resolver,
        private RecommendationRunFinalizer $finalizer,
        private InvalidReplyRetry $invalidReplies,
    ) {
    }

    public function advance(TickContext $tick): RecommendationRunReportModel
    {
        $run = $tick->run;
        $outcome = $this->resolver->resolve($tick);

        if (!$outcome->usable) {
            return $this->invalidReplies->retryOrDegrade(
                $run,
                $outcome->requireUnusableReply(),
                fn (): RecommendationRunReportModel
                    => $this->finalizer->finalize($run, $outcome->requireFallbackRanking()),
            );
        }

        return $this->finalizer->finalize($run, $outcome->ranked);
    }
}
