<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Notification;

use App\Entity\NotificationActor;
use App\Entity\NotificationContext;
use App\Entity\NotificationPostKeys;
use App\Entity\NotificationSubject;
use App\Entity\SocialAccount;
use App\Entity\SocialNotification;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Enum\SocialNotificationKind;
use App\Repository\SocialNotificationRepository;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Social\Notification\Model\FetchedNotificationModel;
use App\Service\Social\Notification\Model\NotificationPageModel;
use App\Service\Social\Notification\NotificationContextLookup;
use App\Service\Social\Notification\NotificationStore;
use App\Tests\DbTestCase;
use App\Tests\Support\RecordingContextSource;
use App\Tests\Support\SocialAccounts;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class NotificationContextLookupTest extends DbTestCase
{
    private NotificationStore $store;
    private SocialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $notifications = $this->entityManager->getRepository(SocialNotification::class);
        self::assertInstanceOf(SocialNotificationRepository::class, $notifications);
        $this->store = new NotificationStore(
            $notifications,
            $this->entityManager,
            new NaiveUtcClock(new MockClock('2026-11-17 12:00:00', 'UTC')),
        );
        $this->account = SocialAccounts::blueskyOf($this->owner());
        $this->entityManager->persist($this->account);
        $this->entityManager->flush();
    }

    public function testHandsOnlyUnstoredRowsToTheContextSource(): void
    {
        $this->store->record($this->account, $this->page([$this->fetched('r1')]));
        $source = new RecordingContextSource();

        $this->lookup($source)->completing($this->account, $this->page([$this->fetched('r1'), $this->fetched('r2')]));

        self::assertSame([['r2']], $source->asked);
    }

    public function testNoUnstoredRowsAsksNothing(): void
    {
        $this->store->record($this->account, $this->page([$this->fetched('r1')]));
        $source = new RecordingContextSource();
        $page = $this->page([$this->fetched('r1')]);

        $completed = $this->lookup($source)->completing($this->account, $page);

        self::assertSame([], $source->asked);
        self::assertSame($page, $completed);
    }

    public function testRowsOutsideRetentionAreNotLookedUp(): void
    {
        $source = new RecordingContextSource();

        $this->lookup($source)->completing($this->account, $this->page([
            $this->fetched('old', '2026-10-17 12:00:00'),
            $this->fetched('fresh'),
        ]));

        self::assertSame([['fresh']], $source->asked);
    }

    public function testContextKeepsTheSeenFlagOfADuplicate(): void
    {
        $source = new RecordingContextSource(new NotificationContext('You said'));
        $keys = new NotificationPostKeys('post-hash', 'answered-hash', 'root-hash');

        $completed = $this->lookup($source)->completing($this->account, $this->page([
            $this->fetched('r1', keys: $keys),
            $this->fetched('r1', seen: true, keys: $keys),
        ]));
        $this->store->record($this->account, $completed);

        $this->entityManager->clear();
        $stored = $this->entityManager->getRepository(SocialNotification::class)->findAll();
        self::assertCount(1, $stored);
        self::assertTrue($stored[0]->isSeen());
        self::assertSame('You said', $stored[0]->getSubject()->context->answeredExcerpt);
        self::assertEquals($keys, $stored[0]->getSubject()->keys);
    }

    #[DataProvider('networks')]
    public function testEveryNetworkResolvesAContextSource(SocialNetwork $network): void
    {
        $account = $this->accountOn($network);
        $lookup = self::getContainer()->get(NotificationContextLookup::class);
        self::assertInstanceOf(NotificationContextLookup::class, $lookup);
        $clock = self::getContainer()->get(NaiveUtcClock::class);
        self::assertInstanceOf(NaiveUtcClock::class, $clock);
        $follow = new FetchedNotificationModel(
            'follow-1',
            SocialNotificationKind::Follow,
            new NotificationActor('bob', null, null, 'https://example.test/bob'),
            NotificationSubject::follow('https://example.test/bob'),
            $clock->now(),
            false,
        );

        $completed = $lookup->completing($account, $this->page([$follow]));

        self::assertEquals(new NotificationContext(), $completed->notifications[0]->subject->context);
    }

    /** @return iterable<string, array{SocialNetwork}> */
    public static function networks(): iterable
    {
        foreach (SocialNetwork::cases() as $network) {
            yield $network->value => [$network];
        }
    }

    private function accountOn(SocialNetwork $network): SocialAccount
    {
        $owner = $this->account->getUser();
        $account = match ($network) {
            SocialNetwork::Bluesky => SocialAccounts::blueskyAs($owner, 'did:plc:context-wiring'),
            SocialNetwork::Mastodon => SocialAccounts::mastodonAs($owner, 'context@mastodon.example'),
            SocialNetwork::Lemmy => SocialAccounts::lemmyOf($owner),
        };
        $this->entityManager->persist($account);
        $this->entityManager->flush();

        return $account;
    }

    private function lookup(RecordingContextSource $source): NotificationContextLookup
    {
        $sources = [];
        foreach (SocialNetwork::cases() as $network) {
            $sources[$network->value] = static fn (): RecordingContextSource => $source;
        }

        return new NotificationContextLookup(new ServiceLocator($sources), $this->store);
    }

    /** @param list<FetchedNotificationModel> $notifications */
    private function page(array $notifications): NotificationPageModel
    {
        return new NotificationPageModel($notifications, null);
    }

    private function fetched(
        string $remoteId,
        string $createdAt = '2026-11-17 11:00:00',
        bool $seen = false,
        NotificationPostKeys $keys = new NotificationPostKeys(),
    ): FetchedNotificationModel {
        return new FetchedNotificationModel(
            $remoteId,
            SocialNotificationKind::Reply,
            new NotificationActor('@bob', null, null, 'https://bsky.app/profile/bob'),
            new NotificationSubject(
                $remoteId,
                'Their reply',
                'https://bsky.app/post/' . $remoteId,
                keys: $keys,
            ),
            new \DateTimeImmutable($createdAt),
            $seen,
        );
    }

    private function owner(): User
    {
        $owner = new User('context-lookup-owner@example.com', new \DateTimeImmutable('2026-07-01 10:00:00'));
        $this->entityManager->persist($owner);
        $this->entityManager->flush();

        return $owner;
    }
}
