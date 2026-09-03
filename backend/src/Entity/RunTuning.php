<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Three per-connection knobs a recommendation run consults, not read together or
 * by one collaborator: `slowModel` picks the timeout profile
 * (ProviderConnectionFactory::timeoutsFor()) and the lock TTL a tick reserves;
 * `batchConcurrency` sizes one tick's wave (RecommendationRunAdvancer::effectiveCap());
 * `maxBatchSize` is the per-connection cap RecommendationSettingsResolver::batchCeilingFor()
 * reads, null meaning the shared default stands (#445). They share one question —
 * how a run should drive this connection — not a caller.
 *
 * `suppressReasoning` answers a related but different question, what one call asks
 * the provider to do (RecommendationCompletionRequestFactory), and stays on
 * AiProviderSettings so it doesn't blur that line.
 *
 * Embedded into AiProviderSettings rather than three of its own columns: PHPMD's
 * field-count ceiling there is a proxy for a real seam — these three arrived as
 * separate features (#344, #433, #445) but answer the one question above. An
 * embeddable groups them without the join or lifecycle a separate entity would
 * add; column names stay unprefixed so the table is unchanged (see FetchSchedule
 * for the same move on Feed).
 */
#[ORM\Embeddable]
class RunTuning
{
    /**
     * How many batch calls a run may send at once for this connection (#344).
     * Default 1 (sequential, pre-#344 behaviour) makes parallelism opt-in: a
     * single-GPU local model risks a memory stampede at higher values, a hosted
     * provider gets a real wall-clock cut. Range enforced at the API
     * (SetBatchConcurrencyRequest); this column is a plain int.
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
     * answers and how long a list it can be trusted with are different properties.
     *
     * Belongs to the connection as configured, not the model: chooseModel()
     * refreshes `modelContextWindow` on a model change but leaves this column
     * alone, so a cap set here stands until the account changes it.
     *
     * Not free either way: #437 watched a 4B local model given 45 entries answer
     * eight batches correctly, then fall into a repetition loop on the ninth,
     * inventing ids until `max_tokens` stopped it — a shorter list bounds the
     * damage. Against that, history is re-sent every batch, so smaller batches
     * mean more calls and more prompt tokens overall.
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

    /**
     * A duplicated connection reuses another's run-tuning rather than starting
     * over at the defaults (AiProviderConfigurator::duplicateConfiguration()).
     * Enumerated here, once, so the fields cannot drift the way a field-by-field
     * copy at the call site did: it copied batchConcurrency and slowModel but was
     * never touched when maxBatchSize was added (#445).
     */
    public function copyFrom(self $source): void
    {
        $this->batchConcurrency = $source->batchConcurrency;
        $this->slowModel = $source->slowModel;
        $this->maxBatchSize = $source->maxBatchSize;
    }
}
