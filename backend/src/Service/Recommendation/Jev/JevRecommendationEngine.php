<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Jev;

use App\Enum\RecommendationEngineKind;
use App\Service\Recommendation\Engine\RecommendationEngine\RecommendationEngineInterface;
use App\Service\Recommendation\Jev\Factory\JevStateFactory;
use App\Service\Recommendation\Jev\Pass\JevWave;
use App\Service\Recommendation\Pool\RecommendationHistoryLoader;
use App\Service\Recommendation\Run\BatchWavePhase;
use App\Service\Recommendation\Run\BatchWaveRounds;
use App\Service\Recommendation\Run\Model\BatchWaveResultModel;
use App\Service\Recommendation\Run\Model\RecommendationRunReportModel;
use App\Service\Recommendation\Run\Model\WaveBatchModel;
use App\Service\Recommendation\Run\Pass\TickContext;
use App\Service\Recommendation\Run\RecommendationRunFailure;
use App\Service\Recommendation\Run\RecommendationRunFinalizer;
use App\Service\Recommendation\Run\RecommendationWinnerRanker;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: RecommendationEngineKind::Jev->value)]
final readonly class JevRecommendationEngine implements RecommendationEngineInterface
{
    public const string NO_PROFILE = 'Jev needs your reading profile, and there is no reading history to build one '
        . 'from yet. Read, keep or favourite a few articles, then start a new run.';

    public function __construct(
        private JevBatchPacker $packer,
        private BatchWavePhase $batchWavePhase,
        private JevStateFactory $stateFactory,
        private JevBatchWave $wave,
        private BatchWaveRounds $rounds,
        private RecommendationWinnerRanker $ranker,
        private RecommendationRunFinalizer $finalizer,
        private RecommendationRunFailure $runFailure,
        private RecommendationHistoryLoader $historyLoader,
    ) {
    }

    public function packBatches(array $candidates, TickContext $tick): array
    {
        return $this->packer->pack($candidates);
    }

    public function advance(TickContext $tick): RecommendationRunReportModel
    {
        $run = $tick->run;
        if (null === $run->getProfileText()) {
            return $this->runFailure->fail($run, self::NO_PROFILE);
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

        $state = $this->stateFactory->create(
            $profile,
            $tick->settings->guidancePrompt,
            $this->historyLoader->favorites($tick->userId(), $tick->settings),
        );

        return new JevWave($tick, $state, $batches);
    }
}
