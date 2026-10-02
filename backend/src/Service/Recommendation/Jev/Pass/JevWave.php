<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Jev\Pass;

use App\Service\Recommendation\Run\BatchWave\BatchWaveInterface;
use App\Service\Recommendation\Run\Model\WaveBatchModel;
use App\Service\Recommendation\Run\Pass\TickContext;

final readonly class JevWave implements BatchWaveInterface
{
    /**
     * @param array<string, string> $state   the reader as System One sees them: the run's profile and the guidance
     * @param list<WaveBatchModel>  $batches the plan's next batches, in plan order
     */
    public function __construct(
        private TickContext $tick,
        public array $state,
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
