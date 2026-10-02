<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run\BatchWave;

use App\Service\Recommendation\Run\Model\WaveBatchModel;
use App\Service\Recommendation\Run\Pass\TickContext;

/** One engine's batch wave, as BatchWaveRounds reads it. */
interface BatchWaveInterface
{
    public function tick(): TickContext;

    /** @return list<WaveBatchModel> the plan's next batches, in plan order */
    public function batches(): array;
}
