<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Recommendation\Run\BatchWave\BatchWaveInterface;
use App\Service\Recommendation\Run\Model\WaveBatchModel;
use App\Service\Recommendation\Run\Pass\TickContext;

final readonly class ScriptedBatchWave implements BatchWaveInterface
{
    /** @param list<WaveBatchModel> $batches */
    public function __construct(
        private TickContext $tick,
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
