<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\NotificationActor;
use App\Entity\NotificationContext;
use App\Entity\NotificationSubject;
use App\Enum\SocialNotificationKind;
use App\Service\Social\Notification\Model\ContextReferenceModel;
use App\Service\Social\Notification\Model\FetchedNotificationModel;

final class NotificationRows
{
    public static function fetched(
        string $remoteId,
        SocialNotificationKind $kind,
        ContextReferenceModel $references = new ContextReferenceModel(),
        NotificationContext $context = new NotificationContext(),
    ): FetchedNotificationModel {
        return new FetchedNotificationModel(
            $remoteId,
            $kind,
            new NotificationActor('dave', null, null, 'https://example.test/@dave'),
            new NotificationSubject($remoteId, 'a reply', 'https://example.test/@dave/' . $remoteId, $context),
            new \DateTimeImmutable('2026-11-16 09:00:00', new \DateTimeZone('UTC')),
            false,
            $references,
        );
    }

    private function __construct()
    {
    }
}
