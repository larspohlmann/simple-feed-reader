<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Entity\User;
use App\Service\Account\Exception\LastAdminException;
use App\Repository\FeedRepository;
use App\Repository\UserRepository;
use App\Service\Admin\SelfActionGuard;
use App\Service\Feed\OrphanedFeedReclaimer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * remove(), not a bulk DQL DELETE, so the unit of work sees what left; what the account owns follows by FK ON DELETE
 * CASCADE. Feeds are shared: only those it was the last subscriber of are reclaimed, by OrphanedFeedReclaimer.
 */
final readonly class AccountDeleter
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $users,
        private FeedRepository $feeds,
        private OrphanedFeedReclaimer $orphanedFeeds,
        private SelfActionGuard $selfActionGuard,
    ) {
    }

    public function deleteAsAdmin(User $target, User $admin): void
    {
        $this->selfActionGuard->ensureNotSelfDeletion($target, $admin);
        $this->delete($target);
    }

    public function deleteSelf(User $user): void
    {
        $this->delete($user);
    }

    private function delete(User $user): void
    {
        $this->ensureNotTheLastAdmin($user);

        $feedIds = $this->feeds->idsSubscribedByUser($user->requireId());

        $this->entityManager->remove($user);
        $this->entityManager->flush();

        foreach ($feedIds as $feedId) {
            $this->orphanedFeeds->reclaim($feedId);
        }
    }

    private function ensureNotTheLastAdmin(User $user): void
    {
        if (!$user->isAdmin()) {
            return;
        }

        if ($this->users->countActiveAdmins() > 1) {
            return;
        }

        throw new LastAdminException();
    }
}
