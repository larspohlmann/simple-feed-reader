<?php

declare(strict_types=1);

namespace App\Service\Curation\StoryAi;

use App\Entity\AiProviderSettings;
use App\Entity\StorySettings;
use App\Entity\User;
use App\Repository\StorySettingsRepository;
use App\Service\Curation\StoryAi\Model\StorySettingsModel;

final readonly class StorySettingsProvider
{
    public function __construct(
        private StorySettingsRepository $storySettings,
        private StoryWritingConnections $writingConnections,
    ) {
    }

    public function forUser(User $user): StorySettingsModel
    {
        $storedSettings = $this->storySettings->findForUser($user);

        return new StorySettingsModel(
            self::hasTitlesEnabled($storedSettings),
            $storedSettings?->getWritingConnection(),
            $this->writingConnections->findUsableConnectionGiven($user, $storedSettings),
            $this->writingConnections->findCandidates($user),
        );
    }

    /** The connection that writes the user's story titles; null while titles are off or none can write. */
    public function findTitleConnection(User $user): ?AiProviderSettings
    {
        $storedSettings = $this->storySettings->findForUser($user);

        return self::hasTitlesEnabled($storedSettings)
            ? $this->writingConnections->findUsableConnectionGiven($user, $storedSettings)
            : null;
    }

    private static function hasTitlesEnabled(?StorySettings $storedSettings): bool
    {
        return $storedSettings?->isTitlesEnabled() ?? StorySettings::DEFAULT_TITLES_ENABLED;
    }
}
