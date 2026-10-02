<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Llm\Run;

use App\Entity\RecommendationRun;
use App\Service\Recommendation\Llm\Prompt\Pass\PromptContext;
use App\Service\Recommendation\Llm\Run\Pass\WaveContext;
use App\Service\Recommendation\Pool\RecommendationCandidateLoader;
use App\Service\Recommendation\Pool\RecommendationHistoryLoader;
use App\Service\Recommendation\Run\Pass\TickContext;
use App\Service\Recommendation\Run\WaveBatchLoader;

final readonly class WaveContextLoader
{
    public function __construct(
        private RecommendationCandidateLoader $candidateLoader,
        private RecommendationHistoryLoader $historyLoader,
        private WaveBatchLoader $batches,
    ) {
    }

    /** The pool summary spans the whole frozen plan: every batch shares one frame, not its own few dates. */
    public function load(TickContext $tick, int $waveSize): WaveContext
    {
        $run = $tick->run;

        return new WaveContext(
            $tick,
            $this->batches->next($tick, $waveSize),
            $this->candidateLoader->summarize($tick->userId(), self::wholePlanIds($run)),
            new PromptContext(
                $this->historyLoader->load($tick->userId(), $tick->settings),
                $tick->settings,
                $run->getProfileText(),
            ),
        );
    }

    /** @return list<int> */
    private static function wholePlanIds(RecommendationRun $run): array
    {
        return array_merge(...$run->getCandidateBatches());
    }
}
