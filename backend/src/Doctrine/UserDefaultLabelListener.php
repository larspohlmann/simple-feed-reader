<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Entity\Label;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsEntityListener(event: Events::prePersist, method: 'prePersist', entity: User::class)]
final readonly class UserDefaultLabelListener
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function prePersist(User $user, PrePersistEventArgs $event): void
    {
        $name = $this->translator->trans('read_later', domain: 'labels', locale: $user->getLocale());
        $event->getObjectManager()->persist(new Label($user, $name));
    }
}
