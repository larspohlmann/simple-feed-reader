<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** The mutable batch-specific checkpoint of a recommendation run. */
#[ORM\Embeddable]
final class RunBatchProgress
{
    #[ORM\Column(options: ['default' => 0])]
    private int $batchesDone = 0;

    #[ORM\Column(options: ['default' => false])]
    private bool $firstBatchStarted = false;

    public function batchesDone(): int
    {
        return $this->batchesDone;
    }

    public function recordCompletedBatch(): void
    {
        $this->batchesDone++;
    }

    public function markFirstBatchStarted(): void
    {
        $this->firstBatchStarted = true;
    }

    public function hasFirstBatchStarted(): bool
    {
        return $this->firstBatchStarted;
    }

    public function completeAllBatches(int $batchesTotal): void
    {
        $this->batchesDone = $batchesTotal;
    }
}
