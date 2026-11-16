<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Notification;

use App\Entity\NotificationActor;
use App\Entity\NotificationSubject;
use App\Entity\SocialAccount;
use App\Entity\SocialNotification;
use App\Entity\User;
use App\Enum\SocialNotificationKind;
use App\Repository\SocialNotificationRepository;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Social\Notification\Model\FetchedNotificationModel;
use App\Service\Social\Notification\Model\NotificationPageModel;
use App\Service\Social\Notification\NotificationStore;
use App\Tests\DbTestCase;
use App\Tests\Support\SocialAccounts;
use Symfony\Component\Clock\MockClock;

final class NotificationStoreTest extends DbTestCase
{
    private NotificationStore $store;
    private SocialNotificationRepository $notifications;
    private SocialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notifications = $this->entityManager->getRepository(SocialNotification::class);
        $this->store = new NotificationStore(
            $this->notifications,
            $this->entityManager,
            new NaiveUtcClock(new MockClock('2026-11-17 12:00:00', 'UTC')),
        );

        $owner = new User('store-owner@example.com', new \DateTimeImmutable('2026-07-01 10:00:00'));
        $this->entityManager->persist($owner);
        $this->entityManager->flush();
        $this->account = SocialAccounts::blueskyOf($owner);
        $this->entityManager->persist($this->account);
        $this->entityManager->flush();
    }

    public function testInsertsNewRowsOldestFirst(): void
    {
        $inserted = $this->record([
            $this->fetched('c', '2026-11-17 11:00:00'),
            $this->fetched('b', '2026-11-17 10:00:00'),
            $this->fetched('a', '2026-11-17 09:00:00'),
        ], null);

        self::assertSame(3, $inserted);

        $this->entityManager->clear();
        $stored = $this->notifications->findBy([], ['id' => 'ASC']);
        self::assertSame(
            ['2026-11-17 09:00:00', '2026-11-17 10:00:00', '2026-11-17 11:00:00'],
            array_map(
                static fn (SocialNotification $row): string => $row->getCreatedAt()->format('Y-m-d H:i:s'),
                $stored,
            ),
        );
    }

    public function testRefetchUpdatesSeenWithoutDuplicating(): void
    {
        $this->record([$this->fetched('a', '2026-11-17 09:00:00')], null);

        $second = $this->record([$this->fetched('a', '2026-11-17 09:00:00', true)], null);

        self::assertSame(0, $second);
        $this->entityManager->clear();
        $stored = $this->notifications->findAll();
        self::assertCount(1, $stored);
        self::assertTrue($stored[0]->isSeen());
    }

    public function testASeenRowNeverTurnsUnseen(): void
    {
        $this->record([$this->fetched('a', '2026-11-17 09:00:00', true)], null);
        $this->record([$this->fetched('a', '2026-11-17 09:00:00')], null);

        $this->entityManager->clear();
        $stored = $this->notifications->findAll();
        self::assertTrue($stored[0]->isSeen());
    }

    public function testSeenUntilMarksOlderStoredRowsSeen(): void
    {
        $this->record([
            $this->fetched('early', '2026-11-17 09:00:00'),
            $this->fetched('late', '2026-11-17 11:00:00'),
        ], null);

        $this->record([], new \DateTimeImmutable('2026-11-17 10:00:00'));

        $this->entityManager->clear();
        $stored = $this->notifications->findBy([], ['id' => 'ASC']);
        self::assertSame(
            [true, false],
            array_map(static fn (SocialNotification $row): bool => $row->isSeen(), $stored),
        );
    }

    public function testSeenUntilLeavesOtherAccountsAlone(): void
    {
        $other = SocialAccounts::blueskyAs($this->account->getUser(), 'did:plc:other');
        $this->entityManager->persist($other);
        $this->entityManager->flush();
        $this->store->record($other, new NotificationPageModel([$this->fetched('x', '2026-11-17 09:00:00')], null));

        $this->record([], new \DateTimeImmutable('2026-11-17 10:00:00'));

        $this->entityManager->clear();
        $stored = $this->notifications->findAll();
        self::assertFalse($stored[0]->isSeen());
    }

    public function testSkipsNotificationsOlderThanThirtyDays(): void
    {
        $stored = $this->record([
            $this->fetched('old', '2026-10-18 11:59:00'),
            $this->fetched('fresh', '2026-10-18 12:01:00'),
        ], null);

        self::assertSame(1, $stored);
        $this->entityManager->clear();
        self::assertSame(['fresh'], $this->remoteIds());
    }

    public function testPruneDeletesOnlyRowsOlderThanThirtyDays(): void
    {
        foreach (['old' => '2026-10-18 11:59:00', 'fresh' => '2026-10-18 12:01:00'] as $remoteId => $createdAt) {
            $this->entityManager->persist(new SocialNotification(
                $this->account,
                $remoteId,
                SocialNotificationKind::Like,
                new NotificationActor('@bob', null, null, 'https://bsky.app/profile/bob'),
                new NotificationSubject('post-1', null, 'https://bsky.app/post-1'),
                new \DateTimeImmutable($createdAt),
                false,
            ));
        }
        $this->entityManager->flush();

        self::assertSame(1, $this->store->prune());

        $this->entityManager->clear();
        self::assertSame(['fresh'], $this->remoteIds());
    }

    public function testFindsRowsByRemoteIdForOneAccount(): void
    {
        $this->record([$this->fetched('a', '2026-11-17 09:00:00')], null);

        self::assertSame(['a'], array_keys($this->notifications->findByRemoteIds($this->account, ['a', 'missing'])));
        self::assertSame([], $this->notifications->findByRemoteIds($this->account, []));
    }

    /** @param list<FetchedNotificationModel> $notifications */
    private function record(array $notifications, ?\DateTimeImmutable $seenUntil): int
    {
        return $this->store->record($this->account, new NotificationPageModel($notifications, $seenUntil));
    }

    /** @return list<string> */
    private function remoteIds(): array
    {
        $rows = $this->notifications->findAll();

        return array_map(static fn (SocialNotification $row): string => $row->getRemoteId(), $rows);
    }

    private function fetched(string $remoteId, string $createdAt, bool $seen = false): FetchedNotificationModel
    {
        return new FetchedNotificationModel(
            $remoteId,
            SocialNotificationKind::Like,
            new NotificationActor('@bob', null, null, 'https://bsky.app/profile/bob'),
            new NotificationSubject('post-1', null, 'https://bsky.app/post-1'),
            new \DateTimeImmutable($createdAt),
            $seen,
        );
    }
}
