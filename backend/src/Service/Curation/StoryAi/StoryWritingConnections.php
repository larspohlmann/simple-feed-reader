<?php

declare(strict_types=1);

namespace App\Service\Curation\StoryAi;

use App\Entity\AiProviderSettings;
use App\Entity\StorySettings;
use App\Entity\User;
use App\Enum\RecommendationEngineKind;
use App\Repository\StorySettingsRepository;
use App\Service\Curation\Ai\AiConfigurationForUser;
use App\Service\Curation\Ai\Support\AiReadiness;

/** Which saved connection writes the account's story text; no feature switch changes the answer. */
final readonly class StoryWritingConnections
{
    public function __construct(
        private StorySettingsRepository $storySettings,
        private AiConfigurationForUser $configurations,
    ) {
    }

    public function usableFor(User $user): ?AiProviderSettings
    {
        return $this->usableGiven($user, $this->storySettings->findForUser($user));
    }

    /** The chosen connection if it can write text, else none; with nothing chosen, the active one if it can. */
    public function usableGiven(User $user, ?StorySettings $storedSettings): ?AiProviderSettings
    {
        $candidate = $storedSettings?->getWritingConnection() ?? $user->getActiveAiProviderSettings();

        return null !== $candidate && $this->canWriteText($candidate) ? $candidate : null;
    }

    public function canWriteText(AiProviderSettings $connection): bool
    {
        return AiReadiness::of($connection) && RecommendationEngineKind::Llm === $connection->getModelKind();
    }

    /** @return list<AiProviderSettings> */
    public function candidatesFor(User $user): array
    {
        return array_values(array_filter(
            $this->configurations->all($user),
            $this->canWriteText(...),
        ));
    }
}
