<?php

declare(strict_types=1);

namespace App\Service\Social\Notification\Model;

use App\Entity\NotificationActor;
use App\Entity\NotificationSubject;
use App\Enum\SocialNotificationKind;

final readonly class FetchedNotificationModel
{
    public function __construct(
        public string $remoteId,
        public SocialNotificationKind $kind,
        public NotificationActor $actor,
        public NotificationSubject $subject,
        public \DateTimeImmutable $createdAt,
        public bool $seen,
        public ContextReferenceModel $references = new ContextReferenceModel(),
    ) {
    }

    #[\NoDiscard]
    public function withSubject(NotificationSubject $subject): self
    {
        return clone($this, ['subject' => $subject]);
    }
}
