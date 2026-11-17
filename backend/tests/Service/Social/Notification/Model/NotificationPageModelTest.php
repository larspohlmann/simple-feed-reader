<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Notification\Model;

use App\Entity\NotificationActor;
use App\Entity\NotificationContext;
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

    public function testWithContextsSetsTheContextOfEveryRowWithThatRemoteId(): void
    {
        $createdAt = new \DateTimeImmutable('2026-11-16 09:00:00');
        $unseen = self::notification('a', $createdAt);
        $seen = new FetchedNotificationModel('a', $unseen->kind, $unseen->actor, $unseen->subject, $createdAt, true);
        $page = new NotificationPageModel([$unseen, $seen, self::notification('b', $createdAt)], $createdAt);
        $context = new NotificationContext('You said');

        $applied = $page->withContexts(['a' => $context, 'z' => new NotificationContext('ignored')]);

        self::assertEquals([$context, $context, new NotificationContext()], array_map(
            static fn (FetchedNotificationModel $notification): NotificationContext => $notification->subject->context,
            $applied->notifications,
        ));
        self::assertSame([false, true, false], array_map(
            static fn (FetchedNotificationModel $notification): bool => $notification->seen,
            $applied->notifications,
        ));
        self::assertCount(3, $applied->notifications);
        self::assertSame($createdAt, $applied->seenUntil);
    }

    public function testWithContextsFindsARowWhoseNumericRemoteIdBecameAnIntegerKey(): void
    {
        $createdAt = new \DateTimeImmutable('2026-11-16 09:00:00');
        $page = new NotificationPageModel([self::notification('123', $createdAt)], null);
        $context = new NotificationContext('You said');

        $applied = $page->withContexts(['123' => $context]);

        self::assertSame($context, $applied->notifications[0]->subject->context);
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
