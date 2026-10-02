<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Jev\Pass;

use App\Service\Recommendation\Run\Model\WaveBatchModel;
use App\Service\Recommendation\Run\Pass\TickContext;

final readonly class JevWave
{
    /**
     * @param array<string, string> $state   the reader as System One sees them: the run's profile and the guidance
     * @param list<WaveBatchModel>  $batches the plan's next batches, in plan order
     */
    public function __construct(
        public TickContext $tick,
        public array $state,
        public array $batches,
    ) {
    }

    public function model(): string
    {
        return $this->tick->connection->getModel()
            ?? throw new \LogicException('A connection ticks only once it has a model.');
    }
}
