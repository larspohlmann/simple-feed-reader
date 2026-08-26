<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Entity\EntryState;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Psr\Clock\ClockInterface;

/**
 * Enforces the one invariant of the hidden model (#482): a viewed entry is always
 * hidden. "Viewed" means the user opened and read the article; "hidden" means it
 * has left the unread list. Every viewed entry must be hidden, so "Recently read" is a
 * strict subset of what the unread filter excludes.
 *
 * The rule lives here, at the persistence boundary, rather than in markViewed():
 * that way no write path can escape it — the web PATCH, a future native iOS
 * client, or a direct field set all pass through onFlush. The bulk "mark all
 * read" UPDATE bypasses ORM events, but it only ever sets the hidden flag, so it
 * cannot violate the invariant and needs no coverage here.
 *
 * hiddenAt takes the entry's own viewedAt when present, so the two timestamps
 * agree; only a viewed row with no viewedAt (which markViewed never produces)
 * falls back to the clock.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final readonly class ViewedImpliesHiddenListener
{
    public function __construct(private ClockInterface $clock)
    {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $entityManager = $args->getObjectManager();
        $unitOfWork = $entityManager->getUnitOfWork();
        $metadata = $entityManager->getClassMetadata(EntryState::class);

        $scheduled = [
            ...$unitOfWork->getScheduledEntityInsertions(),
            ...$unitOfWork->getScheduledEntityUpdates(),
        ];

        foreach ($scheduled as $entity) {
            if (!$entity instanceof EntryState || !$entity->isViewed() || $entity->isHidden()) {
                continue;
            }

            $entity->hide($entity->getViewedAt() ?? $this->clock->now());
            $unitOfWork->recomputeSingleEntityChangeSet($metadata, $entity);
        }
    }
}
