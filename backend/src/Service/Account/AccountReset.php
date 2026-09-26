<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Entity\User;
use App\Repository\AccountWipeRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Empties an account but keeps what identifies or entitles it: the restore's wipe half. Not transactional on purpose:
 * re-running the restore repairs a partial wipe (spec §8). No orphan reclaim: the restore re-subscribes the feeds.
 */
final readonly class AccountReset
{
    public function __construct(
        private AccountWipeRepository $wipe,
        private EntityManagerInterface $em,
    ) {
    }

    public function reset(User $user): void
    {
        $this->wipe->deleteRecommendationData($user);
        $this->wipe->deleteOwnedRows($user);
        $user->getPreferences()->setScrapeFallbackEnabled(false);
        $this->em->flush();
        $this->em->clear();
    }
}
