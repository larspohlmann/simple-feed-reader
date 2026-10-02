<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile;

use App\Entity\AiProviderSettings;
use App\Service\Recommendation\Exception\ProfileConnectionRejectedException;
use App\Service\Recommendation\Exception\ProfileNotBorrowedException;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ProfileConnectionChooser
{
    public const string REJECTION = 'Only a ready LLM connection can build your profile.';

    public const string NOT_BORROWING = 'This connection builds its own profile and borrows none.';

    public function __construct(
        private ProfileConnectionResolver $profileConnections,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws ProfileNotBorrowedException
     * @throws ProfileConnectionRejectedException
     */
    public function choose(AiProviderSettings $borrower, AiProviderSettings $connection): void
    {
        if (!$this->profileConnections->borrows($borrower)) {
            throw new ProfileNotBorrowedException(self::NOT_BORROWING);
        }
        if (!$this->profileConnections->canBuildProfiles($connection)) {
            throw new ProfileConnectionRejectedException(self::REJECTION);
        }

        $borrower->setProfileConnection($connection);
        $this->entityManager->flush();
    }

    public function clear(AiProviderSettings $borrower): void
    {
        $borrower->setProfileConnection(null);
        $this->entityManager->flush();
    }
}
