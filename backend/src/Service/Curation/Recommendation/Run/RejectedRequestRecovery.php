<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Run;

use App\Entity\AiProviderSettings;
use App\Entity\CallFailure;
use App\Repository\CallingRun;
use App\Repository\RecommendationCallRepository;
use App\Service\Curation\Ai\Exception\ProviderRejectedRequestException;
use App\Service\Curation\Recommendation\Run\RejectedRequestFallback\RejectedRequestFallbackInterface;

final readonly class RejectedRequestRecovery
{
    public function __construct(
        private RejectedRequestFallbackInterface $fallback,
        private RecommendationCallRepository $calls,
    ) {
    }

    public function recovers(
        CallingRun $callingRun,
        AiProviderSettings $connection,
        ProviderRejectedRequestException $rejection,
    ): bool {
        $adjustment = $this->fallback->adjust($connection, $rejection);
        if (null === $adjustment) {
            return false;
        }

        $this->calls->recordAdjustment(
            $callingRun,
            CallFailure::adjusted($rejection->getMessage(), $adjustment->setting, $adjustment->value),
        );

        return true;
    }
}
