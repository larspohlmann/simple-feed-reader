<?php

declare(strict_types=1);

namespace App\Service\Curation\StoryAi;

use App\Entity\AiProviderSettings;
use App\Entity\StorySettings;
use App\Entity\User;
use App\Enum\RecommendationEngineKind;
use App\Repository\StorySettingsRepository;
use App\Service\Curation\Ai\ConnectionPicks;
use App\Service\Curation\Ai\Exception\ConfigurationNotFoundException;
use App\Service\Curation\Ai\Exception\ConnectionPickRejectedException;
use App\Service\Curation\Ai\Support\AiReadiness;
use App\Service\Curation\StoryAi\Exception\WritingConnectionRejectedException;

/** Which saved connection writes the account's story text; no feature switch changes the answer. */
final readonly class StoryWritingConnections
{
    public const string REJECTION = 'Only a ready LLM connection can write story text.';

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

    /**
     * @throws ConfigurationNotFoundException when the connection is not this account's
     * @throws WritingConnectionRejectedException when it cannot write text
     */
    public function requireAllowedPickIfSet(
        User $user,
        ?StorySettings $storedSettings,
        ?int $connectionId,
    ): ?AiProviderSettings {
        try {
            return $this->connectionPicks->requireAllowedPickIfSet(
                $user,
                $connectionId,
                $storedSettings?->getWritingConnection(),
                $this->canWriteText(...),
            );
        } catch (ConnectionPickRejectedException $exception) {
            throw new WritingConnectionRejectedException(self::REJECTION, previous: $exception);
        }
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
