<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Llm\Run;

use App\Entity\RecommendationRun;
use App\Service\Curation\Recommendation\Llm\Prompt\Pass\PromptContext;
use App\Service\Curation\Recommendation\Llm\Run\Pass\WaveContext;
use App\Service\Curation\Recommendation\Pool\RecommendationCandidateLoader;
use App\Service\Curation\Recommendation\Pool\RecommendationHistoryLoader;
use App\Service\Curation\Recommendation\Run\Model\WaveBatchModel;
use App\Service\Curation\Recommendation\Run\Pass\TickContext;

final readonly class WaveContextLoader
{
    public function __construct(
        private RecommendationCandidateLoader $candidateLoader,
        private RecommendationHistoryLoader $historyLoader,
    ) {
    }

    /**
     * The pool summary spans the whole frozen plan: every batch shares one frame, not its own few dates.
     *
     * @param list<WaveBatchModel> $batches
     */
    public function load(TickContext $tick, array $batches): WaveContext
    {
        $run = $tick->run;

        return new WaveContext(
            $tick,
            $batches,
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
