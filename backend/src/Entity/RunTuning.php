<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * How a run drives this connection: `slowModel` picks the timeout profile and the lock TTL, `batchConcurrency`
 * sizes a tick's wave (BatchPhase::effectiveCap()), `maxBatchSize` caps a batch (null keeps the default).
 */
#[ORM\Embeddable]
final class RunTuning
{
    /**
     * Default 1 makes parallel batch calls opt-in: a single-GPU local model risks a memory stampede at higher values,
     * where a hosted provider gains wall-clock time. SetBatchConcurrencyRequest enforces the range.
     */
    #[ORM\Column(options: ['default' => 1])]
    private int $batchConcurrency = 1;

    /**
     * Default false: the standard timeouts suit every hosted provider. The row records the account's judgement of
     * the endpoint; ProviderTimeoutsModel owns what the profiles are.
     */
    #[ORM\Column(options: ['default' => 0])]
    private bool $slowModel = false;

    /**
     * Set per connection, not per model: chooseModel() leaves it alone. A shorter list bounds a looping model's damage
     * (#437: a 4B model looped from its ninth batch on), but each batch re-sends history, so small batches cost more.
     */
    #[ORM\Column(nullable: true)]
    private ?int $maxBatchSize = null;

    public function batchConcurrency(): int
    {
        return $this->batchConcurrency;
    }

    public function setBatchConcurrency(int $batchConcurrency): void
    {
        $this->batchConcurrency = $batchConcurrency;
    }

    public function isSlowModel(): bool
    {
        return $this->slowModel;
    }

    public function setSlowModel(bool $slowModel): void
    {
        $this->slowModel = $slowModel;
    }

    public function maxBatchSize(): ?int
    {
        return $this->maxBatchSize;
    }

    public function setMaxBatchSize(?int $maxBatchSize): void
    {
        $this->maxBatchSize = $maxBatchSize;
    }

    /** Every run-tuning field, once, for AiProviderConfigurator::duplicateConfiguration(): add a new field here. */
    public function copyFrom(self $source): void
    {
        $this->batchConcurrency = $source->batchConcurrency;
        $this->slowModel = $source->slowModel;
        $this->maxBatchSize = $source->maxBatchSize;
    }
}
