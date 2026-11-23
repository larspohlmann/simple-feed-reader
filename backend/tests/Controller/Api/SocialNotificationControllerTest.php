<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\CanonicalItem;
use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\NotificationActor;
use App\Entity\NotificationContext;
use App\Entity\NotificationPostKeys;
use App\Entity\NotificationSubject;
use App\Entity\SocialAccount;
use App\Entity\SocialNotification;
use App\Entity\SocialNotificationPoll;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\CanonicalItemKind;
use App\Enum\SocialAccountStatus;
use App\Enum\SocialNotificationKind;
use App\Service\Social\Lemmy\LemmyNotificationSource;
use App\Service\Social\Notification\Model\NotificationPageModel;
use App\Service\Social\Notification\NotificationSource\NotificationSourceInterface;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Social\Exception\SocialNetworkThrottledException;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\MarkingNotificationSource;
use App\Tests\Support\NotificationRows;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialNotificationRows;
use App\Tests\Support\StubNotificationSource;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

final class SocialNotificationControllerTest extends ApiTestCase
{
    private const int REFRESH_BUDGET = 30;

    protected function setUp(): void
    {
        self::bootKernel();
        $rateLimiterCache = self::getContainer()->get('test.cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $rateLimiterCache);
        $rateLimiterCache->clear();
        self::ensureKernelShutdown();
    }

    public function testListReturnsGroupsWithActorsCappedAtThree(): void
    {
        $client = self::createClient();
        $user = $this->signIn($client, 'notification-list@example.test');
        $account = $this->persisted(SocialAccounts::blueskyOf($user));
        $rows = new SocialNotificationRows($this->entityManager());
        for ($index = 0; $index < 4; ++$index) {
            $rows->add($account, SocialNotificationKind::Like, 'at://x/1');
        }
        $newest = $rows->add($account, SocialNotificationKind::Like, 'at://x/1');

        $client->request('GET', '/api/me/notifications?network=bluesky');

        self::assertResponseIsSuccessful();
        $payload = $this->listPayload($client);
        self::assertCount(1, $payload['groups']);
        $group = $payload['groups'][0];
        self::assertIsArray($group['actors']);
        self::assertSame($newest->requireId(), $group['id']);
        self::assertSame('like', $group['kind']);
        self::assertSame('@alice.bsky.social', $group['account']);
        self::assertCount(3, $group['actors']);
        self::assertIsArray($group['actors'][0]);
        self::assertSame(['handle', 'displayName', 'avatarUrl', 'profileUrl'], array_keys($group['actors'][0]));
        self::assertSame(5, $group['actorCount']);
        self::assertSame('excerpt', $group['excerpt']);
        self::assertSame('https://bsky.app/post/at://x/1', $group['linkUrl']);
        self::assertSame('2026-11-16T10:00:00+00:00', $group['latestAt']);
        self::assertFalse($group['seen']);
        foreach (['answeredExcerpt', 'threadTitle', 'threadPlace', 'threadBy'] as $key) {
            self::assertArrayHasKey($key, $group);
            self::assertNull($group[$key]);
        }
        self::assertFalse($group['onComment']);
        self::assertNull($payload['nextCursor']);
        self::assertSame([], $payload['scopeMissing']);
    }

    public function testAMastodonLikeOnASubscribedPostAnswersItsEntryId(): void
    {
        $client = self::createClient();
        $user = $this->signIn($client, 'notification-entry-mastodon@example.test');
        $account = $this->persisted(SocialAccounts::mastodonOf($user));
        $key = 'https://m.test/users/me/statuses/1';
        $entry = $this->persistSubscribedEntry($user, CanonicalItemKind::Mastodon, $key);
        $keys = new NotificationPostKeys(CanonicalItem::hashKey($key));
        $this->persistNotification($account, SocialNotificationKind::Like, $keys);

        $client->request('GET', '/api/me/notifications?network=mastodon');

        self::assertResponseIsSuccessful();
        $group = $this->listPayload($client)['groups'][0];
        self::assertSame($entry->requireId(), $group['entryId']);
        self::assertSame('https://m.test/link', $group['linkUrl']);
    }

    public function testABlueskyReplyResolvesThroughTheAnsweredPost(): void
    {
        $client = self::createClient();
        $user = $this->signIn($client, 'notification-entry-bluesky@example.test');
        $account = $this->persisted(SocialAccounts::blueskyOf($user));
        $answered = 'at://did:plc:me/app.bsky.feed.post/1';
        $entry = $this->persistSubscribedEntry($user, CanonicalItemKind::Bluesky, $answered);
        $this->persistNotification($account, SocialNotificationKind::Reply, new NotificationPostKeys(
            CanonicalItem::hashKey('at://did:plc:bob/app.bsky.feed.post/2'),
            CanonicalItem::hashKey($answered),
        ));

        $client->request('GET', '/api/me/notifications?network=bluesky');

        self::assertResponseIsSuccessful();
        self::assertSame($entry->requireId(), $this->listPayload($client)['groups'][0]['entryId']);
    }

    public function testThePostsEntryBeatsTheAnsweredPostsWhenBothResolve(): void
    {
        $client = self::createClient();
        $user = $this->signIn($client, 'notification-entry-order@example.test');
        $account = $this->persisted(SocialAccounts::blueskyOf($user));
        $post = 'at://did:plc:bob/app.bsky.feed.post/2';
        $answered = 'at://did:plc:me/app.bsky.feed.post/1';
        $this->persistSubscribedEntry($user, CanonicalItemKind::Bluesky, $answered);
        $postEntry = $this->persistSubscribedEntry($user, CanonicalItemKind::Bluesky, $post);
        $keys = new NotificationPostKeys(CanonicalItem::hashKey($post), CanonicalItem::hashKey($answered));
        $this->persistNotification($account, SocialNotificationKind::Reply, $keys);

        $client->request('GET', '/api/me/notifications?network=bluesky');

        self::assertResponseIsSuccessful();
        self::assertSame($postEntry->requireId(), $this->listPayload($client)['groups'][0]['entryId']);
    }

    public function testALemmyReplyAnswersThePostsEntryId(): void
    {
        $client = self::createClient();
        $user = $this->signIn($client, 'notification-entry-lemmy@example.test');
        $account = $this->persisted(SocialAccounts::lemmyOf($user));
        $key = 'https://lemmy.test/post/9';
        $entry = $this->persistSubscribedEntry($user, CanonicalItemKind::Lemmy, $key);
        $keys = new NotificationPostKeys(CanonicalItem::hashKey($key));
        $this->persistNotification($account, SocialNotificationKind::Reply, $keys);

        $client->request('GET', '/api/me/notifications?network=lemmy');

        self::assertResponseIsSuccessful();
        self::assertSame($entry->requireId(), $this->listPayload($client)['groups'][0]['entryId']);
    }

    public function testAPostOnlyInAnotherUsersFeedAnswersNoEntryId(): void
    {
        $client = self::createClient();
        $user = $this->signIn($client, 'notification-entry-other@example.test');
        $other = $this->factory()->create('notification-entry-owner@example.test');
        $account = $this->persisted(SocialAccounts::mastodonOf($user));
        $key = 'https://m.test/users/me/statuses/2';
        $this->persistSubscribedEntry($other, CanonicalItemKind::Mastodon, $key);
        $keys = new NotificationPostKeys(CanonicalItem::hashKey($key));
        $this->persistNotification($account, SocialNotificationKind::Like, $keys);

        $client->request('GET', '/api/me/notifications?network=mastodon');

        self::assertResponseIsSuccessful();
        $group = $this->listPayload($client)['groups'][0];
        self::assertArrayHasKey('entryId', $group);
        self::assertNull($group['entryId']);
        self::assertSame('https://m.test/link', $group['linkUrl']);
    }

    public function testAFollowAnswersNoEntryId(): void
    {
        $client = self::createClient();
        $user = $this->signIn($client, 'notification-entry-follow@example.test');
        $account = $this->persisted(SocialAccounts::mastodonOf($user));
        $this->persistNotification($account, SocialNotificationKind::Follow, new NotificationPostKeys());

        $client->request('GET', '/api/me/notifications?network=mastodon');

        self::assertResponseIsSuccessful();
        $group = $this->listPayload($client)['groups'][0];
        self::assertArrayHasKey('entryId', $group);
        self::assertNull($group['entryId']);
    }

    private function persistNotification(
        SocialAccount $account,
        SocialNotificationKind $kind,
        NotificationPostKeys $keys,
    ): void {
        $this->entityManager()->persist(new SocialNotification(
            $account,
            'remote-entry',
            $kind,
            new NotificationActor('@bob', null, null, 'https://m.test/@bob'),
            (new NotificationSubject('group', 'excerpt', 'https://m.test/link'))->withKeys($keys),
            new \DateTimeImmutable('2026-11-16 10:00:00'),
            false,
        ));
        $this->entityManager()->flush();
    }

    private function persistSubscribedEntry(User $user, CanonicalItemKind $kind, string $canonicalKey): Entry
    {
        $createdAt = new \DateTimeImmutable('2026-11-20 08:30:00');
        $feed = new Feed('https://feeds.test/' . md5($canonicalKey));
        $this->entityManager()->persist($feed);
        $this->entityManager()->persist(new Subscription($user, $feed, $createdAt));
        $item = new CanonicalItem($kind, $canonicalKey, $createdAt);
        $this->entityManager()->persist($item);
        $entry = new Entry($feed, 'guid-' . $canonicalKey, null, 'Title', $createdAt, $createdAt);
        $entry->linkTo($item);
        $this->entityManager()->persist($entry);
        $this->entityManager()->flush();

        return $entry;
    }

    public function testAGroupCarriesItsContext(): void
    {
        $client = self::createClient();
        $user = $this->signIn($client, 'notification-context@example.test');
        $account = $this->persisted(SocialAccounts::lemmyOf($user));
        $subject = (new NotificationSubject('c9', 'reply text', 'https://lemmy.test/comment/9'))
            ->withContext((new NotificationContext('my comment', 'Post title', 'c/php', null))->withOnComment());
        $this->entityManager()->persist(new SocialNotification(
            $account,
            'reply:9',
            SocialNotificationKind::Reply,
            new NotificationActor('@bob@lemmy.test', null, null, 'https://lemmy.test/u/bob'),
            $subject,
            new \DateTimeImmutable('2026-11-16 10:00:00'),
            false,
        ));
        $this->entityManager()->flush();

        $client->request('GET', '/api/me/notifications?network=lemmy');

        self::assertResponseIsSuccessful();
        $group = $this->listPayload($client)['groups'][0];
        self::assertSame('reply', $group['kind']);
        self::assertTrue($group['onComment']);
        self::assertSame('my comment', $group['answeredExcerpt']);
        self::assertSame('Post title', $group['threadTitle']);
        self::assertSame('c/php', $group['threadPlace']);
        self::assertNull($group['threadBy']);
    }

    public function testAFullPageCarriesTheCursorOfItsLastGroup(): void
    {
        $client = self::createClient();
        $user = $this->signIn($client, 'notification-cursor@example.test');
        $account = $this->persisted(SocialAccounts::blueskyOf($user));
        $rows = new SocialNotificationRows($this->entityManager());
        for ($index = 0; $index < 32; ++$index) {
            $rows->add($account, SocialNotificationKind::Reply, 'at://x/' . $index);
        }

        $client->request('GET', '/api/me/notifications?network=bluesky');
        $first = $this->listPayload($client);
        self::assertCount(30, $first['groups']);
        $cursor = $first['nextCursor'];
        self::assertSame($first['groups'][29]['id'], $cursor);

        $client->request('GET', '/api/me/notifications?network=bluesky&cursor=' . $cursor);
        $second = $this->listPayload($client);
        self::assertCount(2, $second['groups']);
        self::assertNull($second['nextCursor']);
    }

    public function testANetworkWithoutAccountsListsNothing(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'notification-none@example.test');

        $client->request('GET', '/api/me/notifications?network=lemmy');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['groups' => [], 'nextCursor' => null, 'scopeMissing' => [], 'needsReconnect' => []],
            $this->payload($client),
        );
    }

    public function testUnknownNetworkIs400(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'notification-unknown@example.test');

        $client->request('GET', '/api/me/notifications?network=friendster');

        $this->assertRejected($client, Response::HTTP_BAD_REQUEST);
    }

    public function testBadCursorIs400(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'notification-bad-cursor@example.test');

        $client->request('GET', '/api/me/notifications?network=bluesky&cursor=abc');

        $this->assertRejected($client, Response::HTTP_BAD_REQUEST);
    }

    public function testSeenReturns204AndMarksTheRows(): void
    {
        $client = self::createClient();
        $user = $this->signIn($client, 'notification-seen@example.test');
        $account = $this->persisted(SocialAccounts::lemmyOf($user));
        $rows = new SocialNotificationRows($this->entityManager());
        $rowId = $rows->add($account, SocialNotificationKind::Reply, 'c1')->requireId();
        $source = new MarkingNotificationSource();
        $this->useSource($source);

        $this->postSeen($client, ['network' => 'lemmy', 'upTo' => $rowId]);

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertSame([$rowId], $source->handed[$account->getIdentity()]);
        $this->entityManager()->clear();
        $stored = $this->entityManager()->find(SocialNotification::class, $rowId);
        self::assertNotNull($stored);
        self::assertTrue($stored->isSeen());
    }

    public function testSeenMapsAThrottledNetworkTo503WithRetryAfter(): void
    {
        $client = self::createClient();
        $user = $this->signIn($client, 'notification-seen-throttled@example.test');
        $account = $this->persisted(SocialAccounts::lemmyOf($user));
        $rows = new SocialNotificationRows($this->entityManager());
        $rowId = $rows->add($account, SocialNotificationKind::Reply, 'c1')->requireId();
        $this->useSource(new MarkingNotificationSource(0, new SocialNetworkThrottledException('slow down', 120)));

        $this->postSeen($client, ['network' => 'lemmy', 'upTo' => $rowId]);

        $this->assertRejected($client, Response::HTTP_SERVICE_UNAVAILABLE);
        self::assertSame('120', $client->getResponse()->headers->get('Retry-After'));
    }

    public function testUpToOfAnotherUserMarksNothing(): void
    {
        $client = self::createClient();
        $user = $this->signIn($client, 'notification-seen-own@example.test');
        $other = $this->factory()->create('notification-seen-other@example.test');
        $rows = new SocialNotificationRows($this->entityManager());
        $otherRow = $rows->add($this->persisted(SocialAccounts::blueskyOf($other)), SocialNotificationKind::Like, 'a');
        $otherRowId = $otherRow->requireId();
        $this->persisted(SocialAccounts::blueskyAs($user, 'did:plc:mine'));

        $this->postSeen($client, ['network' => 'bluesky', 'upTo' => $otherRowId + 100]);

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->entityManager()->clear();
        $stored = $this->entityManager()->find(SocialNotification::class, $otherRowId);
        self::assertNotNull($stored);
        self::assertFalse($stored->isSeen());
    }

    public function testMissingUpToIs422(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'notification-seen-missing@example.test');

        $this->postSeen($client, ['network' => 'bluesky']);

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testRefreshReturns204AndStoresTheFetchedNotifications(): void
    {
        $client = self::createClient();
        $user = $this->signIn($client, 'notification-refresh@example.test');
        $account = $this->persisted(SocialAccounts::lemmyOf($user));
        $this->useSource(new StubNotificationSource([
            $account->getIdentity() => new NotificationPageModel(
                [NotificationRows::fetched('c-refresh', SocialNotificationKind::Reply)],
                null,
            ),
        ]));

        $this->postRefresh($client, ['network' => 'lemmy']);

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $client->request('GET', '/api/me/notifications?network=lemmy');
        $groups = $this->listPayload($client)['groups'];
        self::assertCount(1, $groups);
        self::assertSame('reply', $groups[0]['kind']);
    }

    public function testRefreshMapsRejectedCredentialsToItsProblemAndMarksTheAccount(): void
    {
        $client = self::createClient();
        $user = $this->signIn($client, 'notification-refresh-rejected@example.test');
        $account = $this->persisted(SocialAccounts::lemmyOf($user));
        $accountId = $account->requireId();
        $this->useSource(new StubNotificationSource([
            $account->getIdentity() => new SocialCredentialsRejectedException('Lemmy refused the token.'),
        ]));

        $this->postRefresh($client, ['network' => 'lemmy']);

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->entityManager()->clear();
        $stored = $this->entityManager()->find(SocialAccount::class, $accountId);
        self::assertNotNull($stored);
        self::assertSame(SocialAccountStatus::NeedsReconnect, $stored->getStatus());
    }

    public function testRefreshIsRateLimitedPerUser(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'notification-refresh-limited@example.test');
        for ($attempt = 0; $attempt < self::REFRESH_BUDGET; ++$attempt) {
            $this->postRefresh($client, ['network' => 'bluesky']);
            self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        }

        $this->postRefresh($client, ['network' => 'bluesky']);

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
    }

    public function testRefreshWithoutANetworkIs422(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'notification-refresh-missing@example.test');

        $this->postRefresh($client, []);

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testRefreshNeedsAToken(): void
    {
        $client = self::createClient();

        $this->postRefresh($client, ['network' => 'bluesky']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /** @param array<string, mixed> $body */
    private function postRefresh(KernelBrowser $client, array $body): void
    {
        $client->request(
            'POST',
            '/api/me/notifications/refresh',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    private function useSource(NotificationSourceInterface $source): void
    {
        self::getContainer()->set(LemmyNotificationSource::class, $source);
    }

    /** @param array<string, mixed> $body */
    private function postSeen(KernelBrowser $client, array $body): void
    {
        $client->request(
            'POST',
            '/api/me/notifications/seen',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    public function testListsScopeMissingHandles(): void
    {
        $client = self::createClient();
        $user = $this->signIn($client, 'notification-scope@example.test');
        $account = $this->persisted(SocialAccounts::mastodonOf($user));
        $poll = new SocialNotificationPoll($account);
        $poll->recordScopeMissing(new \DateTimeImmutable('2026-11-16 10:00:00'));
        $this->entityManager()->persist($poll);
        $this->entityManager()->flush();

        $client->request('GET', '/api/me/notifications?network=mastodon');

        self::assertResponseIsSuccessful();
        self::assertSame([$account->getHandle()], $this->payload($client)['scopeMissing']);
    }

    public function testListsNeedsReconnectHandles(): void
    {
        $client = self::createClient();
        $user = $this->signIn($client, 'notification-reconnect@example.test');
        $account = SocialAccounts::mastodonOf($user);
        $account->markNeedsReconnect();
        $this->persisted($account);
        $this->persisted(SocialAccounts::blueskyAs($user, 'did:plc:fine'));

        $client->request('GET', '/api/me/notifications?network=mastodon');

        self::assertResponseIsSuccessful();
        self::assertSame([$account->getHandle()], $this->payload($client)['needsReconnect']);
    }

    public function testListNeedsAToken(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/me/notifications?network=bluesky');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /** @return array{groups: list<array<string, mixed>>, nextCursor: int|null, scopeMissing: list<string>, needsReconnect: list<string>} */
    private function listPayload(KernelBrowser $client): array
    {
        /** @var array{groups: list<array<string, mixed>>, nextCursor: int|null, scopeMissing: list<string>, needsReconnect: list<string>} $payload */
        $payload = $this->payload($client);

        return $payload;
    }

    private function persisted(SocialAccount $account): SocialAccount
    {
        $this->entityManager()->persist($account);
        $this->entityManager()->flush();

        return $account;
    }
}
