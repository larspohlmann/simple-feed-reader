<?php

declare(strict_types=1);

namespace App\Service\Curation\StoryAi;

use App\Entity\AiProviderSettings;
use App\Entity\StorySettings;
use App\Entity\User;
use App\Enum\RecommendationEngineKind;
use App\Repository\StorySettingsRepository;
use App\Service\Curation\Ai\ConnectionPicks;
use App\Service\Curation\Ai\Support\AiReadiness;

/** Which saved connection writes the account's story text; no feature switch changes the answer. */
final readonly class StoryWritingConnections
{
    public function __construct(
        private StorySettingsRepository $storySettings,
        private ConnectionPicks $connectionPicks,
    ) {
    }

    public function findUsableConnection(User $user): ?AiProviderSettings
    {
        return $this->findUsableConnectionGiven($user, $this->storySettings->findForUser($user));
    }

    public function findUsableConnectionGiven(User $user, ?StorySettings $storedSettings): ?AiProviderSettings
    {
        return $this->connectionPicks->findUsableConnectionGiven(
            $user,
            $storedSettings?->getWritingConnection(),
            $this->canWriteText(...),
        );
    }

    public function canWriteText(AiProviderSettings $connection): bool
    {
        return AiReadiness::isReady($connection) && RecommendationEngineKind::Llm === $connection->getModelKind();
    }

    /** @return list<AiProviderSettings> */
    public function findCandidates(User $user): array
    {
        return $this->connectionPicks->findCandidates($user, $this->canWriteText(...));
    }
}
