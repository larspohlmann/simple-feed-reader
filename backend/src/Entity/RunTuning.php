<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * How a connection should be driven while a recommendation run calls it: how
 * many batch calls may be in flight at once, whether the endpoint needs the
 * long timeout profile, and how many candidates one batch may carry.
 *
 * Embedded into AiProviderSettings rather than left as three of its own
 * scalar columns — PHPMD's field-count ceiling on AiProviderSettings is a
 * proxy for a real seam: these three values are read together by
 * RecommendationSettingsResolver to size one run against one connection, and
 * they arrived as three separate features (#344, #433, #445) that share the
 * same concern. An embeddable keeps them there without the join or lifecycle
 * a separate entity would add; the column names are unprefixed so the table
 * itself is unchanged (see FetchSchedule for the same move on Feed).
 */
#[ORM\Embeddable]
class RunTuning
{
    /**
     * How many batch calls a run may send at once for this connection (#344).
     * Default 1: sequential, identical to the pre-#344 behaviour, so
     * parallelism is strictly opt-in per connection. A single-GPU local model
     * gains nothing from a higher value and the low ceiling keeps a wave from
     * a memory stampede; a hosted provider gets a real wall-clock cut. The
     * range is enforced at the API (SetBatchConcurrencyRequest); this column
     * is a plain int so a value written straight to the row is still read back.
     */
    #[ORM\Column(options: ['default' => 1])]
    private int $batchConcurrency = 1;

    /**
     * Whether this endpoint answers slowly enough to need the long timeout
     * profile (#433). Default false: the standard bounds are right for every
     * hosted provider, and a connection only earns the long ones by being
     * marked. What the two profiles are is ProviderTimeouts' business — the
     * row records the account's judgement about the endpoint, not a duration.
     */
    #[ORM\Column(options: ['default' => 0])]
    private bool $slowModel = false;

    /**
     * How many candidates one batch of this connection's run may carry, or null
     * to take the default. Split off `slow_model` in #445: how fast an endpoint
     * answers and how long a list its model holds in order are different
     * properties, and one flag could not express a large model on slow hardware.
     *
     * The cap is not free either way. #437 watched a 4B local model given 45
     * entries answer eight batches correctly and fall into a repetition loop on
     * the ninth, inventing ids until `max_tokens` stopped it — a shorter list is
     * easier to hold in order, and it bounds what one runaway costs. Against
     * that, the history sections are re-sent with every batch, so smaller
     * batches mean more calls and more prompt tokens over the run.
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
}
