<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Entity\User;
use App\Exception\LastAdminException;
use App\Repository\FeedRepository;
use App\Repository\UserRepository;
use App\Service\Admin\SelfActionGuard;
use App\Service\OrphanedFeedReclaimer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Hard deletion of an account and everything it owns. Two entry points because
 * the guards differ: an admin must not delete themselves through the admin API,
 * while a user deleting their own account is the whole point of deleteSelf().
 * Both refuse to remove the last administrator.
 *
 * remove(), not a bulk DQL DELETE: going through the ORM keeps the unit of work
 * aware of what left, the same reasoning recorded on E2ePurgeUsersCommand. The
 * account's subscriptions, tags, read state, preferences, identities and action
 * tokens follow through their FK ON DELETE CASCADE.
 *
 * Feeds are NOT the user's content — other people read them — so they are not
 * cascaded. Only the feeds this account was the last subscriber of are
 * reclaimed, and that decision belongs to OrphanedFeedReclaimer, which
 * re-checks it inside its DELETE.
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

        $feedIds = $this->feeds->idsSubscribedByUser((int) $user->getId());

        $this->entityManager->remove($user);
        $this->entityManager->flush();

        foreach ($feedIds as $feedId) {
            $this->orphanedFeeds->reclaim($feedId);
        }
    }

    private function ensureNotTheLastAdmin(User $user): void
    {
        if (!\in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return;
        }

        if ($this->users->countActiveAdmins() > 1) {
            return;
        }

        throw new LastAdminException();
    }
}
