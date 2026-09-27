<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

/**
 * Finalizes the consolidated list; an unusable reply is retried, then the run completes with the undeduped
 * batch-score pool (#493).
 */
final readonly class ConsolidationPhase implements ProviderPhase
{
    public function __construct(
        private RecommendationConsolidationResolver $resolver,
        private RecommendationRunFinalizer $finalizer,
        private InvalidReplyRetry $invalidReplies,
    ) {
    }

    public function advance(TickContext $tick): RecommendationRunReport
    {
        $run = $tick->run;
        $outcome = $this->resolver->resolve($tick);

        if (!$outcome->usable) {
            return $this->invalidReplies->retryOrDegrade(
                $run,
                $outcome->requireUnusableReply(),
                fn (): RecommendationRunReport => $this->finalizer->finalize($run, $outcome->requireFallbackPool()),
            );
        }

        return $this->finalizer->finalize($run, $outcome->ranked);
    }
}
