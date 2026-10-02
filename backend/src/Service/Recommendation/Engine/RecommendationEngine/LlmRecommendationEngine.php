<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Engine\RecommendationEngine;

use App\Entity\RecommendationRun;
use App\Service\Recommendation\Engine\Model\RecommendationEngineCapabilitiesModel;
use App\Service\Recommendation\Engine\Model\RecommendationEngineKind;
use App\Service\Recommendation\Engine\Model\RecommendationTuningField;
use App\Service\Recommendation\Prompt\RecommendationHistoryLoader;
use App\Service\Recommendation\Prompt\RecommendationPromptBuilder;
use App\Service\Recommendation\Run\Model\RecommendationRunReportModel;
use App\Service\Recommendation\Run\Pass\TickContext;
use App\Service\Recommendation\Run\ProviderPhase\BatchPhase;
use App\Service\Recommendation\Run\ProviderPhase\ConsolidationPhase;
use App\Service\Recommendation\Run\ProviderPhase\DistillationPhase;
use App\Service\Recommendation\Run\ProviderPhase\ProviderPhaseInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/** The chat-completion engine: packs by the context window, then distills, scores in batches and consolidates. */
#[AsTaggedItem(index: RecommendationEngineKind::Llm->value)]
final readonly class LlmRecommendationEngine implements RecommendationEngineInterface
{
    public function __construct(
        private RecommendationHistoryLoader $historyLoader,
        private RecommendationPromptBuilder $promptBuilder,
        private DistillationPhase $distillation,
        private BatchPhase $batch,
        private ConsolidationPhase $consolidation,
    ) {
    }

    public function packBatches(array $candidates, TickContext $tick): array
    {
        return $this->promptBuilder->packBatches(
            $candidates,
            $this->historyLoader->load($tick->userId(), $tick->settings),
            $tick->settings,
        );
    }

    public function advance(TickContext $tick): RecommendationRunReportModel
    {
        return $this->providerPhaseFor($tick->run)->advance($tick);
    }

    public function capabilities(): RecommendationEngineCapabilitiesModel
    {
        return new RecommendationEngineCapabilitiesModel(true, RecommendationTuningField::cases());
    }

    private function providerPhaseFor(RecommendationRun $run): ProviderPhaseInterface
    {
        $progress = $run->getProgress();

        return match (true) {
            $progress->distillPending => $this->distillation,
            $progress->isConsolidationPhase => $this->consolidation,
            default => $this->batch,
        };
    }
}
