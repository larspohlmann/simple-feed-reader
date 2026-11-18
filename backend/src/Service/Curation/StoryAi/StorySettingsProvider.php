<?php

declare(strict_types=1);

namespace App\Service\Curation\StoryAi;

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

    public function titlesEnabledFor(User $user): bool
    {
        return self::titlesEnabledIn($this->storySettings->findForUser($user));
    }

    private static function titlesEnabledIn(?StorySettings $storedSettings): bool
    {
        return $storedSettings?->isTitlesEnabled() ?? StorySettings::DEFAULT_TITLES_ENABLED;
    }
}
