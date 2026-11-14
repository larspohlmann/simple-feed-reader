<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Service\Recommendation\Pool\RecommendationCandidateLoader;
use App\Service\Recommendation\Run\Model\WaveBatchModel;
use App\Service\Recommendation\Run\Pass\TickContext;

final readonly class WaveBatchLoader
{
    public function __construct(private RecommendationCandidateLoader $candidateLoader)
    {
    }

    /** @return list<WaveBatchModel> the frozen plan's next $waveSize batches, in plan order */
    public function next(TickContext $tick, int $waveSize): array
    {
        $startIndex = $tick->run->getProgress()->nextBatchIndex;
        $candidateBatches = $tick->run->getCandidateBatches();

        $idsByPosition = [];
        for ($index = $startIndex; $index < $startIndex + $waveSize; $index++) {
            $idsByPosition[$index] = $candidateBatches[$index];
        }

        $linesById = $this->candidateLoader->linesForIds($tick->userId(), array_merge(...array_values($idsByPosition)));

        $batches = [];
        foreach ($idsByPosition as $index => $ids) {
            $batches[] = new WaveBatchModel($index, $ids, array_intersect_key($linesById, array_flip($ids)));
        }

        return $batches;
    }
}
