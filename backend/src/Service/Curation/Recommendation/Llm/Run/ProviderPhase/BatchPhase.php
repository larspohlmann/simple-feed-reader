<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Llm\Run\ProviderPhase;

use App\Service\Recommendation\Llm\Run\RecommendationBatchWave;
use App\Service\Recommendation\Llm\Run\WaveContextLoader;
use App\Service\Recommendation\Run\BatchWavePhase;
use App\Service\Recommendation\Run\BatchWaveRounds;
use App\Service\Recommendation\Run\Model\BatchWaveResultModel;
use App\Service\Recommendation\Run\Model\RecommendationRunReportModel;
use App\Service\Recommendation\Run\Pass\TickContext;

final readonly class BatchPhase implements ProviderPhaseInterface
{
    public function __construct(
        private WaveContextLoader $waves,
        private RecommendationBatchWave $batchWave,
        private BatchWavePhase $batchWavePhase,
        private BatchWaveRounds $rounds,
    ) {
    }

    public function advance(TickContext $tick): RecommendationRunReportModel
    {
        return $this->batchWavePhase->advance(
            $tick,
            fn (array $batches): BatchWaveResultModel => $this->rounds->resolve(
                $this->batchWave,
                $this->waves->load($tick, $batches),
            ),
        );
    }
}
