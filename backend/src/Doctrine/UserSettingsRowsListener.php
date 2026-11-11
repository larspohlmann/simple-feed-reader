<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Entity\ReadingSettings;
use App\Entity\SharingSettings;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;

/** The settings repositories read a row per account and never create one; this is where every account gets them. */
#[AsEntityListener(event: Events::prePersist, method: 'prePersist', entity: User::class)]
final readonly class UserSettingsRowsListener
{
    public function prePersist(User $user, PrePersistEventArgs $event): void
    {
        $objectManager = $event->getObjectManager();
        $objectManager->persist(new ReadingSettings($user));
        $objectManager->persist(new SharingSettings($user));
    }
}
