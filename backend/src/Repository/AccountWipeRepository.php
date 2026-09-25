<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EntryState;
use App\Entity\RecommendationItem;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Entity\RecommendationSettings;
use App\Entity\SavedSearch;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/** Bulk DELETEs of everything a user owns; they bypass the identity map, so the caller must clear() it. */
final readonly class AccountWipeRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function deleteRecommendationData(User $user): void
    {
        // Redundant with the run FK's ON DELETE CASCADE on purpose: the wipe's scope stays readable in one place.
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

    public function deleteOwnedRows(User $user): void
    {
        $this->deleteByUser(EntryState::class, $user);
        // subscription_tag rows die with their subscription and tag through both join columns' ON DELETE CASCADE.
        $this->deleteByUser(Subscription::class, $user);
        $this->deleteByUser(Tag::class, $user);
        $this->deleteByUser(SavedSearch::class, $user);
    }

    /** @param class-string $entityClass */
    private function deleteByUser(string $entityClass, User $user): void
    {
        $this->em->createQuery(sprintf('DELETE FROM %s x WHERE x.user = :user', $entityClass))
            ->setParameter('user', $user)
            ->execute();
    }
}
