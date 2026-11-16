<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\NotificationActor;
use App\Entity\NotificationSubject;
use App\Entity\SocialAccount;
use App\Entity\SocialNotification;
use App\Enum\SocialNotificationKind;
use Doctrine\ORM\EntityManagerInterface;

/** Persists notification rows oldest first, so ids ascend with the order of the calls. */
final class SocialNotificationRows
{
    private int $sequence = 0;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function add(
        SocialAccount $account,
        SocialNotificationKind $kind,
        string $groupKey,
        bool $seen = false,
    ): SocialNotification {
        ++$this->sequence;
        $row = new SocialNotification(
            $account,
            'remote-' . $this->sequence,
            $kind,
            new NotificationActor(
                '@actor' . $this->sequence,
                'Actor ' . $this->sequence,
                null,
                'https://bsky.app/profile/actor' . $this->sequence,
            ),
            new NotificationSubject($groupKey, 'excerpt', 'https://bsky.app/post/' . $groupKey),
            new \DateTimeImmutable('2026-11-16 10:00:00'),
            $seen,
        );
        $this->entityManager->persist($row);
        $this->entityManager->flush();

        return $row;
    }
}
