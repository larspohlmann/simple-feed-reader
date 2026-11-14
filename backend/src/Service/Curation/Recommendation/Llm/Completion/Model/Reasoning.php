<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Llm\Completion\Model;

use App\Entity\AiProviderSettings;

/** Whether a call asks the provider not to reason: a hint a local model may ignore, so budgets keep room. */
enum Reasoning
{
    case Allowed;
    case Suppressed;

    public static function preferredBy(AiProviderSettings $connection): self
    {
        return $connection->suppressesReasoning() && !$connection->refusesSuppressedReasoning()
            ? self::Suppressed
            : self::Allowed;
    }
}
