<?php

declare(strict_types=1);

namespace App\Service\Curation\StoryAi;

use App\Entity\AiProviderSettings;
use App\Entity\StorySettings;
use App\Entity\User;
use App\Repository\StorySettingsRepository;
use App\Service\Curation\Ai\AiConfigurationForUser;
use App\Service\Curation\Ai\Exception\ConfigurationNotFoundException;
use App\Service\Curation\StoryAi\Exception\WritingConnectionRejectedException;
use App\Service\Curation\StoryAi\Model\StorySettingsChangeModel;
use Doctrine\ORM\EntityManagerInterface;

final readonly class StorySettingsEditor
{
    public const string REJECTION = 'Only a ready LLM connection can write story text.';

    public function __construct(
        private AiConfigurationForUser $configurations,
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
        $writingConnection = $this->connectionFor($user, $storedSettings, $change->writingConnectionId);

        if (null === $storedSettings) {
            $storedSettings = new StorySettings($user);
            $this->entityManager->persist($storedSettings);
        }
        $storedSettings->update($writingConnection, $change->titlesEnabled);
        $this->entityManager->flush();
    }

    private function connectionFor(User $user, ?StorySettings $storedSettings, ?int $connectionId): ?AiProviderSettings
    {
        if (null === $connectionId) {
            return null;
        }

        $connection = $this->configurations->require($user, $connectionId);
        $isStoredPick = $storedSettings?->getWritingConnection() === $connection;
        if (!$isStoredPick && !$this->writingConnections->canWriteText($connection)) {
            throw new WritingConnectionRejectedException(self::REJECTION);
        }

        return $connection;
    }
}
