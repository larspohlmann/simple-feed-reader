<?php

declare(strict_types=1);

namespace App\Service\Social\Notification\Model;

use App\Entity\NotificationActor;
use App\Entity\NotificationSubject;
use App\Enum\SocialNotificationKind;

final readonly class NotificationGroupModel
{
    /** @param list<NotificationActor> $actors the newest few; $actorCount counts them all */
    public function __construct(
        public int $latestId,
        public SocialNotificationKind $kind,
        public string $accountHandle,
        public array $actors,
        public int $actorCount,
        public NotificationSubject $subject,
        public \DateTimeImmutable $latestAt,
        public bool $seen,
        public ?int $entryId,
    ) {
    }
}
