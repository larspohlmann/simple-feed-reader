<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run\RejectedRequestFallback;

use App\Entity\AiProviderSettings;
use App\Service\Ai\Exception\ProviderRejectedRequestException;
use App\Service\Recommendation\Run\Model\RequestAdjustmentModel;

/** Specific readings first: the suppressed-reasoning member absorbs any 400 and would misfile the others' refusals. */
final readonly class CompositeRejectedRequestFallback implements RejectedRequestFallbackInterface
{
    public function __construct(
        private RejectedRequestFallbackInterface $tokenParameter,
        private RejectedRequestFallbackInterface $outputCap,
        private RejectedRequestFallbackInterface $suppressedReasoning,
    ) {
    }

    public function adjust(
        AiProviderSettings $connection,
        ProviderRejectedRequestException $rejection,
    ): ?RequestAdjustmentModel {
        return $this->tokenParameter->adjust($connection, $rejection)
            ?? $this->outputCap->adjust($connection, $rejection)
            ?? $this->suppressedReasoning->adjust($connection, $rejection);
    }
}
