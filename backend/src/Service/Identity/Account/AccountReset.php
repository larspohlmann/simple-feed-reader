<?php

declare(strict_types=1);

namespace App\Service\Identity\Account;

use App\Entity\User;
use App\Repository\AccountWipeRepository;
use App\Repository\ReadingSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Empties an account but keeps what identifies or entitles it: the restore's wipe half. Not transactional on purpose:
 * re-running the restore repairs a partial wipe (spec §8). No orphan reclaim: the restore re-subscribes the feeds.
 */
final readonly class AccountReset
{
    public function __construct(
        private AccountWipeRepository $wipe,
        private ReadingSettingsRepository $readingSettings,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function reset(User $user): void
    {
        $this->wipe->deleteRecommendationData($user);
        $this->wipe->deleteOwnedRows($user);
        $this->wipe->deletePrivateFeedSyncMemory($user);
        $this->readingSettings->forUser($user)->setScrapeFallbackEnabled(false);
        $this->entityManager->flush();
        $this->entityManager->clear();
    }
}
