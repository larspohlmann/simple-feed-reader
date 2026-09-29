<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Entity\EntryState;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Psr\Clock\ClockInterface;

/**
 * Keeps viewed ⇒ hidden on every flush, whatever the write path; hiddenAt takes the entry's own viewedAt. The bulk
 * "mark all read" UPDATE skips ORM events, but it only ever hides, so it cannot break the rule.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final readonly class ViewedImpliesHiddenListener
{
    public function __construct(private ClockInterface $clock)
    {
    }

    public function onFlush(OnFlushEventArgs $event): void
    {
        $entityManager = $event->getObjectManager();
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
