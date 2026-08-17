<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Entity\EntryState;
use App\Entity\RecommendationItem;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Entity\RecommendationSettings;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Empties an account without deleting it: everything the user owns goes,
 * everything that identifies or entitles the account stays (email, password,
 * roles, status, limits, AI connections, OAuth identities). The restore's
 * wipe half, deliberately its own named service — it is the most destructive
 * code in the repository, and a future admin "reset user" action calls the
 * same method rather than growing a second wipe.
 *
 * Bulk DQL, not remove(): entry_state alone can hold tens of thousands of
 * rows. The DELETEs bypass the identity map, so this method ends with a
 * clear() — and so must every test that asserts a row is gone.
 *
 * No orphaned-feed reclaim here, unlike AccountDeleter: the restore
 * re-subscribes the same feeds moments later, and reclaiming in between
 * would delete entries only to re-insert them from the file. A caller that
 * wipes WITHOUT reloading owns that decision itself.
 *
 * Not transactional, deliberately: a mid-wipe crash leaves a partially
 * emptied account, and the recovery is re-running the same backup file
 * through the restore, not rolling back (spec §8). A future caller must not
 * assume reset() is all-or-nothing.
 */
final readonly class AccountReset
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function reset(User $user): void
    {
        $this->deleteRecommendationData($user);
        $this->deleteOwnedRows($user);
        $user->getPreferences()->setScrapeFallbackEnabled(false);
        $this->em->flush();
        $this->em->clear();
    }

    private function deleteRecommendationData(User $user): void
    {
        // The run's FK on both children already carries ON DELETE CASCADE, so
        // the DB would clear these either way — deleting them explicitly is
        // deliberate redundancy: it keeps the wipe's scope readable in one
        // place, independent of the mapping.
        foreach ([RecommendationItem::class, RecommendationRunLog::class] as $childClass) {
            $this->em->createQuery(sprintf(
                'DELETE FROM %s c WHERE IDENTITY(c.run) IN (SELECT r.id FROM %s r WHERE r.user = :user)',
                $childClass,
                RecommendationRun::class,
            ))->setParameter('user', $user)->execute();
        }
        $this->deleteByUser(RecommendationRun::class, $user);
        $this->deleteByUser(RecommendationSettings::class, $user);
    }

    private function deleteOwnedRows(User $user): void
    {
        $this->deleteByUser(EntryState::class, $user);
        // subscription_tag rows die with their subscription (and tag) via the
        // DB-level ON DELETE CASCADE both join columns declare.
        $this->deleteByUser(Subscription::class, $user);
        $this->deleteByUser(Tag::class, $user);
    }

    /** @param class-string $entityClass */
    private function deleteByUser(string $entityClass, User $user): void
    {
        $this->em->createQuery(sprintf('DELETE FROM %s x WHERE x.user = :user', $entityClass))
            ->setParameter('user', $user)
            ->execute();
    }
}
