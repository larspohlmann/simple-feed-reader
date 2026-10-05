<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Llm\Run;

use App\Entity\AiProviderSettings;
use App\Service\Ai\Exception\ProviderRejectedRequestException;
use App\Service\Recommendation\Engine\Model\RecommendationTuningField;
use App\Service\Recommendation\Engine\RecommendationEngineResolver;
use App\Service\Recommendation\Llm\Completion\Model\Reasoning;
use App\Service\Recommendation\Run\RejectedRequestFallback\RejectedRequestFallbackInterface;
use Doctrine\ORM\EntityManagerInterface;

/** The provider's sentence is not parsed: any 400 or 422 to a suppressing request may be the model refusing it. */
final readonly class SuppressedReasoningFallback implements RejectedRequestFallbackInterface
{
    private const array STATUSES_A_REFUSED_PARAMETER_EARNS = [400, 422];

    public function __construct(
        private RecommendationEngineResolver $engines,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function absorbs(AiProviderSettings $connection, ProviderRejectedRequestException $rejection): bool
    {
        if (!$this->mayBeARefusedSuppression($connection, $rejection)) {
            return false;
        }

        $connection->recordSuppressionRefused();
        $this->entityManager->flush();

        return true;
    }

    private function mayBeARefusedSuppression(
        AiProviderSettings $connection,
        ProviderRejectedRequestException $rejection,
    ): bool {
        return \in_array($rejection->status(), self::STATUSES_A_REFUSED_PARAMETER_EARNS, true)
            && Reasoning::Suppressed === Reasoning::preferredBy($connection)
            && \in_array(
                RecommendationTuningField::SuppressReasoning,
                $this->engines->capabilitiesFor($connection)->tuningFields,
                true,
            );
    }
}
