<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * What a recommendation run cost: the provider, the model, and the provider's own token and price accounting.
 * RecommendationCallRepository adds each call's usage in SQL, never through this object, so a wave's concurrent calls
 * lose no increment. That is why the counters have no setter.
 */
#[ORM\Embeddable]
final class ProviderUsage
{
    /**
     * Copied at start, not read through the editable configuration, so history never renames last month's runs.
     * Null on runs older than the column, or that failed before stamp().
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $providerHost = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $model = null;

    /** This and the next three: summed over every call, retries and an aborted wave's discarded siblings included. */
    #[ORM\Column(options: ['default' => 0])]
    private int $promptTokens = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $completionTokens = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $reasoningTokens = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $cachedTokens = 0;

    /**
     * Nano-credits in a BIGINT: money is never a float, and credits × 1e9 outgrows INT at 2.1 credits. Null means no
     * call reported a price (a local model, an older run), which must not read as free.
     */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    // @phpstan-ignore property.unusedType (only the repository's SQL and Doctrine's hydration assign it)
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
