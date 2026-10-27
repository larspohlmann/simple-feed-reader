<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\AiProviderSettings;
use App\Repository\CallingRun;
use App\Repository\RecommendationCallRepository;
use App\Service\Ai\Exception\ProviderRejectedRequestException;
use App\Service\Recommendation\Run\RejectedRequestFallback\RejectedRequestFallbackInterface;

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

        $this->calls->recordAdjustment($callingRun, $adjustment);

        return true;
    }
}
