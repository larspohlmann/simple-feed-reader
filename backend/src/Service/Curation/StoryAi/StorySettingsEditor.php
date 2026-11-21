<?php

declare(strict_types=1);

namespace App\Service\Curation\StoryAi;

use App\Entity\StorySettings;
use App\Entity\User;
use App\Repository\StorySettingsRepository;
use App\Service\Curation\Ai\Exception\ConfigurationNotFoundException;
use App\Service\Curation\StoryAi\Exception\WritingConnectionRejectedException;
use App\Service\Curation\StoryAi\Model\StorySettingsChangeModel;
use Doctrine\ORM\EntityManagerInterface;

final readonly class StorySettingsEditor
{
    public function __construct(
        private StoryWritingConnections $writingConnections,
        private StorySettingsRepository $storySettings,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws ConfigurationNotFoundException when the connection is not this account's
     * @throws WritingConnectionRejectedException when it cannot write text
     */
    public function save(User $user, StorySettingsChangeModel $change): void
    {
        $storedSettings = $this->storySettings->findForUser($user);
        $writingConnection = $this->writingConnections->requireAllowedPickIfSet(
            $user,
            $storedSettings,
            $change->writingConnectionId,
        );

        if (null === $storedSettings) {
            $storedSettings = new StorySettings($user);
            $this->entityManager->persist($storedSettings);
        }
        $storedSettings->update($writingConnection, $change->titlesEnabled);
        $this->entityManager->flush();
    }
}
