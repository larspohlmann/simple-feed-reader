<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\RecommendationRun;
use App\Service\Recommendation\Prompt\RecommendationCandidateLoader;
use App\Service\Recommendation\Prompt\RecommendationHistoryLoader;

final readonly class WaveContextLoader
{
    public function __construct(
        private RecommendationCandidateLoader $candidateLoader,
        private RecommendationHistoryLoader $historyLoader,
    ) {
    }

    /** The pool summary spans the whole frozen plan: every batch shares one frame, not its own few dates (#344). */
    public function load(TickContext $tick, int $waveSize): WaveContext
    {
        $run = $tick->run;

        return new WaveContext(
            $tick,
            $this->nextBatches($tick, $waveSize),
            $this->candidateLoader->summarize($tick->userId(), self::wholePlanIds($run)),
            $this->historyLoader->load($tick->userId(), $tick->settings),
            $run->getProfileText(),
        );
    }

    /** @return list<int> */
    private static function wholePlanIds(RecommendationRun $run): array
    {
        return array_merge(...$run->getCandidateBatches());
    }

    /**
     * One linesForIds() round trip for the whole wave, split back per batch; an entry pruned since the snapshot
     * is simply absent from its batch's lines.
     *
     * @return list<WaveBatch>
     */
    private function nextBatches(TickContext $tick, int $waveSize): array
    {
        $startIndex = $tick->run->progress()->nextBatchIndex;
        $candidateBatches = $tick->run->getCandidateBatches();

        $idsByPosition = [];
        for ($index = $startIndex; $index < $startIndex + $waveSize; $index++) {
            $idsByPosition[$index] = $candidateBatches[$index];
        }

        $linesById = $this->candidateLoader->linesForIds($tick->userId(), array_merge(...array_values($idsByPosition)));

        $batches = [];
        foreach ($idsByPosition as $index => $ids) {
            $batches[] = new WaveBatch($index, $ids, array_intersect_key($linesById, array_flip($ids)));
        }

        return $batches;
    }
}
