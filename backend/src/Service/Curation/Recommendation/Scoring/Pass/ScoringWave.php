<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Scoring\Pass;

use App\Service\Curation\Recommendation\Run\BatchWave\BatchWaveInterface;
use App\Service\Curation\Recommendation\Run\Model\WaveBatchModel;
use App\Service\Curation\Recommendation\Run\Pass\TickContext;
use App\Service\Curation\Recommendation\Scoring\Model\ScoringReaderModel;

final readonly class ScoringWave implements BatchWaveInterface
{
    /** @param list<WaveBatchModel> $batches the plan's next batches, in plan order */
    public function __construct(
        private TickContext $tick,
        public ScoringReaderModel $reader,
        private array $batches,
    ) {
    }

    public function tick(): TickContext
    {
        return $this->tick;
    }

    public function batches(): array
    {
        return $this->batches;
    }
}
