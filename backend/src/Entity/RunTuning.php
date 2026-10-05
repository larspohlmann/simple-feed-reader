<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * How a run drives this connection: `slowModel` picks the timeout profile and the lock TTL, `batchConcurrency`
 * sizes a tick's wave (BatchWavePhase::effectiveCap()), `maxBatchSize` caps a batch (null keeps the default).
 */
#[ORM\Embeddable]
final class RunTuning
{
    /**
     * The hard ceiling on one tick's wave of provider calls; the default stays 1. Only the worker reaches it:
     * a poll or sweep tick clamps to BatchWavePhase::POLL_MAX_CONCURRENCY.
     */
    public const int MAX_BATCH_CONCURRENCY = 8;

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

    /** The configured concurrency clamped to the ceiling a direct-DB value could exceed. */
    public function cappedBatchConcurrency(): int
    {
        return min($this->batchConcurrency, self::MAX_BATCH_CONCURRENCY);
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

    /**
     * Every run-tuning field, once, for AiProviderConfigurator::duplicateConfiguration(): a copy starts out driven
     * the same way as its source, not reset to the defaults. Add a new field here.
     */
    public function copyFrom(self $source): void
    {
        $this->batchConcurrency = $source->batchConcurrency;
        $this->slowModel = $source->slowModel;
        $this->maxBatchSize = $source->maxBatchSize;
    }
}
