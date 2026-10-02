<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Jev;

use App\Enum\RecommendationEngineKind;
use App\Service\Recommendation\Engine\RecommendationEngine\RecommendationEngineInterface;
use App\Service\Recommendation\Jev\Factory\JevStateFactory;
use App\Service\Recommendation\Jev\Pass\JevWave;
use App\Service\Recommendation\Pool\RecommendationHistoryLoader;
use App\Service\Recommendation\Run\BatchWavePhase;
use App\Service\Recommendation\Run\Model\BatchWaveResultModel;
use App\Service\Recommendation\Run\Model\RecommendationRunReportModel;
use App\Service\Recommendation\Run\Model\WaveBatchModel;
use App\Service\Recommendation\Run\Pass\TickContext;
use App\Service\Recommendation\Run\RecommendationRunFinalizer;
use App\Service\Recommendation\Run\RecommendationWinnerRanker;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * TypeSafe's System One: packs by its 64k request budget, asks one Noul per candidate in waves, ranks the answers.
 * No reasons and no consolidation: once every batch is in, the list is the best-scored picks.
 */
#[AsTaggedItem(index: RecommendationEngineKind::Jev->value)]
final readonly class JevRecommendationEngine implements RecommendationEngineInterface
{
    public function __construct(
        private JevBatchPacker $packer,
        private BatchWavePhase $batchWavePhase,
        private RecommendationHistoryLoader $historyLoader,
        private JevStateFactory $stateFactory,
        private JevBatchWave $wave,
        private RecommendationWinnerRanker $ranker,
        private RecommendationRunFinalizer $finalizer,
    ) {
    }

    public function packBatches(array $candidates, TickContext $tick): array
    {
        return $this->packer->pack($candidates);
    }

    public function advance(TickContext $tick): RecommendationRunReportModel
    {
        $run = $tick->run;
        if ($run->getProgress()->allBatchCallsDone) {
            return $this->finalizer->finalize($run, $this->ranker->ranked($run->getWinners()));
        }

        return $this->batchWavePhase->advance(
            $tick,
            fn (array $batches): BatchWaveResultModel => $this->wave->resolve($this->waveOf($tick, $batches)),
        );
    }

    /** @param list<WaveBatchModel> $batches */
    private function waveOf(TickContext $tick, array $batches): JevWave
    {
        return new JevWave(
            $tick,
            $this->stateFactory->create(
                $tick->settings->guidancePrompt,
                $this->historyLoader->load($tick->userId(), $tick->settings),
            ),
            $batches,
        );
    }
}
