<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * What a recommendation run cost: the provider it called, the model, and the
 * provider's own token and price accounting for every call the run made.
 *
 * Embedded into RecommendationRun rather than left as seven of its own scalar
 * columns — PHPMD's field-count ceiling on RecommendationRun is a proxy for a
 * real seam: these seven values are stamped and banked together, by
 * RecommendationRunAdvancer and RecordedCall, and belong to the same concern
 * (#409). An embeddable keeps them there without the join or lifecycle a
 * separate entity would add; the column names are unprefixed so the table
 * itself is unchanged (see FetchSchedule for the same move on Feed).
 */
#[ORM\Embeddable]
class ProviderUsage
{
    /**
     * The provider this run actually called, copied onto the run at start
     * rather than read back through the account's configuration (#409). The
     * configuration is editable, and a history that renames last month's runs
     * when the model changes is not a history. Null on runs that predate this
     * column, and on one that failed before it was ever stamped.
     *
     * The host only, not the whole base URL: the host is what identifies the
     * provider, and a path adds nothing a history row can use.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $providerHost = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $model = null;

    /**
     * The provider's own token accounting for this run, summed over every call
     * it made — retries and the discarded siblings of an aborted wave
     * included, because the provider billed those too (#344, #409).
     *
     * Written by RecordedCall through DBAL with SQL arithmetic, never through
     * this object: concurrent calls of one wave would otherwise lose each
     * other's increments, and the advancer's EntityManager must not be flushed
     * mid-tick. This object only ever reads them, which is why there is no
     * setter — a second writer is exactly the race the SQL avoids.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $promptTokens = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $completionTokens = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $reasoningTokens = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $cachedTokens = 0;

    /**
     * What this run cost, in nano-credits. Money, so an integer and never a
     * float. BIGINT because credits × 1e9 outgrows INT at 2.1 credits, and it
     * hydrates as a PHP int because DBAL 4's BigIntType returns one for every
     * value inside PHP's integer range — which nano-credits never leave.
     *
     * Null means no call of this run reported a price: a local model, or a
     * run that predates this column. Deliberately not 0, which would claim the
     * run was free.
     *
     * PHPStan sees only this class's code, so it reads the property as
     * always null — nothing here ever assigns it an int, by the same design
     * that gives it no setter (see the class doc). Doctrine's hydration
     * populates the real value when a row RecordedCall priced is loaded back;
     * that assignment happens through reflection, invisible to static
     * analysis.
     */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    // @phpstan-ignore property.unusedType
    private ?int $costNanoCredits = null;

    /**
     * Records which provider and model a run is about to use. Called at
     * start and again at resume, so a run resumed after the account switched
     * models is stamped with the model it will actually call.
     */
    public function stamp(?string $providerHost, ?string $model): void
    {
        $this->providerHost = $providerHost;
        $this->model = $model;
    }

    public function getProviderHost(): ?string
    {
        return $this->providerHost;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function getPromptTokens(): int
    {
        return $this->promptTokens;
    }

    public function getCompletionTokens(): int
    {
        return $this->completionTokens;
    }

    public function getReasoningTokens(): int
    {
        return $this->reasoningTokens;
    }

    public function getCachedTokens(): int
    {
        return $this->cachedTokens;
    }

    public function getCostNanoCredits(): ?int
    {
        return $this->costNanoCredits;
    }
}
