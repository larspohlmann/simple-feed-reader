<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Three per-connection knobs a recommendation run consults — not read together,
 * and not by one collaborator: `slowModel` picks the timeout profile
 * (ProviderConnectionFactory::timeoutsFor()), and through it the lock TTL a tick
 * reserves, and nothing else since #445 split the batch ceiling off it;
 * `batchConcurrency` sizes one tick's wave (RecommendationRunAdvancer::effectiveCap());
 * `maxBatchSize` is what RecommendationSettingsResolver::batchCeilingFor() reads
 * for its per-connection cap, null meaning no claim and the shared default stands
 * (#445). What groups them is not a shared caller but a shared question: how a run
 * should drive this connection, decided once per connection.
 *
 * `suppressReasoning` answers a related but different question — what one call asks
 * the provider to do (RecommendationCompletionRequestFactory) — and stays on
 * AiProviderSettings: it shapes a request's payload, not the run's pacing or
 * ceilings, and folding it in here would blur that line for a field count this
 * class does not need it to clear.
 *
 * Embedded into AiProviderSettings rather than three of its own scalar columns —
 * PHPMD's field-count ceiling on AiProviderSettings is a proxy for a real seam:
 * these three arrived as separate features (#344, #433, #445) but answer the one
 * question above. An embeddable keeps them there without the join or lifecycle a
 * separate entity would add; the column names are unprefixed so the table is
 * unchanged (see FetchSchedule for the same move on Feed).
 */
#[ORM\Embeddable]
class RunTuning
{
    /**
     * How many batch calls a run may send at once for this connection (#344).
     * Default 1: sequential, identical to the pre-#344 behaviour, so
     * parallelism is strictly opt-in per connection. A single-GPU local model
     * gains nothing from a higher value and risks a memory stampede; a hosted
     * provider gets a real wall-clock cut. The range is enforced at the API
     * (SetBatchConcurrencyRequest); this column is a plain int so a value
     * written straight to the row is still read back.
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
     * answers and how long a list it can be trusted with are different
     * properties, and one flag could not express a large model on slow hardware.
     *
     * The cap belongs to the connection as the account configured it, not to
     * the model identifier: AiProviderSettings::chooseModel() refreshes
     * `modelContextWindow` when the model changes and deliberately leaves this
     * column alone, so a cap set here stands until the account changes it.
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

    /**
     * A duplicated connection reuses another's run-tuning rather than
     * starting over at the defaults (AiProviderConfigurator::
     * duplicateConfiguration()). Enumerated here, once, so the three fields
     * cannot drift out of sync with this class the way a field-by-field copy
     * at the call site did: it copied batchConcurrency and slowModel but was
     * never touched when maxBatchSize was added (#445).
     */
    public function copyFrom(self $source): void
    {
        $this->batchConcurrency = $source->batchConcurrency;
        $this->slowModel = $source->slowModel;
        $this->maxBatchSize = $source->maxBatchSize;
    }
}
