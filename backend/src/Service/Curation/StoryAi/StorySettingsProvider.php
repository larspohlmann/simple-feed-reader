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
            self::titlesEnabledIn($storedSettings),
            $storedSettings?->getWritingConnection(),
            $this->writingConnections->usableGiven($user, $storedSettings),
            $this->writingConnections->candidatesFor($user),
        );
    }

    /** The connection that writes the user's story titles; null while titles are off or none can write. */
    public function titleConnectionFor(User $user): ?AiProviderSettings
    {
        $storedSettings = $this->storySettings->findForUser($user);

        return self::titlesEnabledIn($storedSettings)
            ? $this->writingConnections->usableGiven($user, $storedSettings)
            : null;
    }

    private static function titlesEnabledIn(?StorySettings $storedSettings): bool
    {
        return $storedSettings?->isTitlesEnabled() ?? StorySettings::DEFAULT_TITLES_ENABLED;
    }
}
