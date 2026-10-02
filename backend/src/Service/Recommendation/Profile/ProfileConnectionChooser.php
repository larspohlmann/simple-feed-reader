<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile;

use App\Entity\AiProviderSettings;
use App\Repository\AiProviderSettingsRepository;
use App\Service\Recommendation\Exception\ProfileConnectionRejectedException;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ProfileConnectionChooser
{
    public const string REJECTION = 'Only a ready LLM connection can build your profile.';

    public function __construct(
        private ProfileConnectionResolver $profileConnections,
        private AiProviderSettingsRepository $aiProviderSettings,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** @throws ProfileConnectionRejectedException */
    public function choose(AiProviderSettings $connection): void
    {
        if (!$this->profileConnections->canBuildProfiles($connection)) {
            throw new ProfileConnectionRejectedException(self::REJECTION);
        }

        $chosenId = $connection->requireId();
        foreach ($this->aiProviderSettings->findAllForUser($connection->getUser()) as $sibling) {
            $sibling->setProfileSource($sibling->requireId() === $chosenId);
        }
        $this->entityManager->flush();
    }

    /** Idempotent: a connection that holds no choice stays as it is. */
    public function clear(AiProviderSettings $connection): void
    {
        $connection->setProfileSource(false);
        $this->entityManager->flush();
    }
}
