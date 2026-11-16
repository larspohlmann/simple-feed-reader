<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Notification\Model;

use App\Entity\NotificationActor;
use App\Entity\NotificationSubject;
use App\Enum\SocialNotificationKind;
use App\Service\Social\Notification\Model\FetchedNotificationModel;
use App\Service\Social\Notification\Model\NotificationPageModel;
use PHPUnit\Framework\TestCase;

final class NotificationPageModelTest extends TestCase
{
    public function testOldestFirstSortsByCreatedAtThenRemoteId(): void
    {
        $tied = new \DateTimeImmutable('2026-11-16 09:00:00');
        $page = new NotificationPageModel([
            self::notification('c', new \DateTimeImmutable('2026-11-16 10:00:00')),
            self::notification('b', $tied),
            self::notification('a', $tied),
        ], null);

        self::assertSame(['a', 'b', 'c'], array_map(
            static fn (FetchedNotificationModel $notification): string => $notification->remoteId,
            $page->oldestFirst(),
        ));
    }

    private static function notification(string $remoteId, \DateTimeImmutable $createdAt): FetchedNotificationModel
    {
        return new FetchedNotificationModel(
            $remoteId,
            SocialNotificationKind::Follow,
            new NotificationActor('bob.test', null, null, 'https://bsky.app/profile/did:plc:bob'),
            new NotificationSubject('', null, 'https://bsky.app/profile/did:plc:bob'),
            $createdAt,
            false,
        );
    }
}
