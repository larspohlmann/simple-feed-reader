<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Jev;

use App\Enum\RecommendationEngineKind;
use App\Service\Recommendation\Engine\RecommendationEngine\RecommendationEngineInterface;
use App\Service\Recommendation\Jev\Factory\JevStateFactory;
use App\Service\Recommendation\Jev\Pass\JevWave;
use App\Service\Recommendation\Run\BatchWavePhase;
use App\Service\Recommendation\Run\BatchWaveRounds;
use App\Service\Recommendation\Run\Model\BatchWaveResultModel;
use App\Service\Recommendation\Run\Model\RecommendationRunReportModel;
use App\Service\Recommendation\Run\Model\WaveBatchModel;
use App\Service\Recommendation\Run\Pass\TickContext;
use App\Service\Recommendation\Run\RecommendationRunFinalizer;
use App\Service\Recommendation\Run\RecommendationWinnerRanker;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: RecommendationEngineKind::Jev->value)]
final readonly class JevRecommendationEngine implements RecommendationEngineInterface
{
    public function __construct(
        private JevBatchPacker $packer,
        private BatchWavePhase $batchWavePhase,
        private JevStateFactory $stateFactory,
        private JevBatchWave $wave,
        private BatchWaveRounds $rounds,
        private RecommendationWinnerRanker $ranker,
        private RecommendationRunFinalizer $finalizer,
        private JevProfileStep $profileStep,
    ) {
    }

    public function packBatches(array $candidates, TickContext $tick): array
    {
        return $this->packer->pack($candidates);
    }

    public function advance(TickContext $tick): RecommendationRunReportModel
    {
        $run = $tick->run;
        if ($this->profileStep->isPending($run)) {
            return $this->profileStep->advance($tick);
        }
        if ($run->getProgress()->allBatchCallsDone) {
            return $this->finalizer->finalize($run, $this->ranker->ranked($run->getWinners()));
        }

        return $this->batchWavePhase->advance(
            $tick,
            fn (array $batches): BatchWaveResultModel => $this->rounds->resolve(
                $this->wave,
                $this->waveOf($tick, $batches),
            ),
        );
    }

    /** @param list<WaveBatchModel> $batches */
    private function waveOf(TickContext $tick, array $batches): JevWave
    {
        $profile = $tick->run->getProfileText()
            ?? throw new \LogicException('A Jev wave runs only once the run holds a profile.');

        return new JevWave($tick, $this->stateFactory->create($profile, $tick->settings->guidancePrompt), $batches);
    }
}
