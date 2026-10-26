<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Llm\Prompt\Model;

use App\Entity\AiProviderSettings;
use App\Service\Recommendation\Llm\Completion\Model\Reasoning;

/** What bounds a connection's output: the reasoning headroom it keeps and the model's cap, null where none is known. */
final readonly class OutputLimitModel
{
    public function __construct(public Reasoning $reasoning, public ?int $capTokens)
    {
    }

    public function clamp(int $tokens): int
    {
        return null === $this->capTokens ? $tokens : min($tokens, $this->capTokens);
    }

    public function admits(int $tokens): bool
    {
        return null === $this->capTokens || $tokens <= $this->capTokens;
    }

    public static function of(AiProviderSettings $connection): self
    {
        return new self(Reasoning::preferredBy($connection), $connection->getModelMaxOutputTokens());
    }
}
