<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Scoring;

use App\Enum\RecommendationEngineKind;
use App\Service\Curation\Recommendation\Engine\RecommendationEngine\RecommendationEngineInterface;
use App\Service\Curation\Recommendation\Pool\RecommendationHistoryLoader;
use App\Service\Curation\Recommendation\Run\BatchWavePhase;
use App\Service\Curation\Recommendation\Run\BatchWaveRounds;
use App\Service\Curation\Recommendation\Run\Model\BatchWaveResultModel;
use App\Service\Curation\Recommendation\Run\Model\RecommendationRunReportModel;
use App\Service\Curation\Recommendation\Run\Model\WaveBatchModel;
use App\Service\Curation\Recommendation\Run\Pass\TickContext;
use App\Service\Curation\Recommendation\Run\RecommendationRunFailure;
use App\Service\Curation\Recommendation\Run\RecommendationRunFinalizer;
use App\Service\Curation\Recommendation\Run\RecommendationWinnerRanker;
use App\Service\Curation\Recommendation\Scoring\Model\ScoringReaderModel;
use App\Service\Curation\Recommendation\Scoring\Pass\ScoringWave;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: RecommendationEngineKind::Scoring->value)]
final readonly class ScoringRecommendationEngine implements RecommendationEngineInterface
{
    public const string NO_PROFILE = 'A scoring model needs your reading profile, and there is no reading history to '
        . 'build one from yet. Read, keep or favourite a few articles, then start a new run.';

    public function __construct(
        private ScoringProtocolResolver $protocols,
        private BatchWavePhase $batchWavePhase,
        private ScoringBatchWave $wave,
        private BatchWaveRounds $rounds,
        private RecommendationWinnerRanker $ranker,
        private RecommendationRunFinalizer $finalizer,
        private RecommendationRunFailure $runFailure,
        private RecommendationHistoryLoader $historyLoader,
    ) {
    }

    public function packBatches(array $candidates, TickContext $tick): array
    {
        $protocol = $this->protocols->protocolOf($tick->requireScoringProtocol());

        return $protocol->pack($protocol->budget($tick->requireScoringContextWindow()), $candidates);
    }

    public function advance(TickContext $tick): RecommendationRunReportModel
    {
        $run = $tick->run;
        if ($run->isMissingBorrowedProfile()) {
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
    private function waveOf(TickContext $tick, array $batches): ScoringWave
    {
        $profile = $tick->run->getProfileText()
            ?? throw new \LogicException('A scoring wave runs only once the run holds a profile.');

        return new ScoringWave(
            $tick,
            new ScoringReaderModel(
                $profile,
                $tick->settings->guidancePrompt,
                $this->historyLoader->favorites($tick->userId(), $tick->settings),
            ),
            $batches,
        );
    }
}
