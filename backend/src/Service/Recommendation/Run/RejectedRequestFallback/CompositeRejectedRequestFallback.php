<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run\RejectedRequestFallback;

use App\Entity\AiProviderSettings;
use App\Service\Ai\Exception\ProviderRejectedRequestException;

/** Specific readings first: the suppressed-reasoning member absorbs any 400 and would misfile the others' refusals. */
final readonly class CompositeRejectedRequestFallback implements RejectedRequestFallbackInterface
{
    public function __construct(
        private RejectedRequestFallbackInterface $tokenParameter,
        private RejectedRequestFallbackInterface $outputCap,
        private RejectedRequestFallbackInterface $suppressedReasoning,
    ) {
    }

    public function absorbs(AiProviderSettings $connection, ProviderRejectedRequestException $rejection): bool
    {
        return $this->tokenParameter->absorbs($connection, $rejection)
            || $this->outputCap->absorbs($connection, $rejection)
            || $this->suppressedReasoning->absorbs($connection, $rejection);
    }
}
