<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Bluesky;

use App\Entity\NotificationActor;
use App\Entity\NotificationSubject;
use App\Entity\SocialAccount;
use App\Entity\SocialNotification;
use App\Enum\SocialNotificationKind;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Social\Bluesky\BlueskyPostLookup;
use App\Service\Social\Notification\Model\ContextReferenceModel;
use App\Service\Social\Notification\Model\FetchedNotificationModel;
use App\Service\Social\Bluesky\BlueskyNotificationSource;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Tests\Support\ReadsFixtures;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class BlueskyNotificationSourceTest extends TestCase
{
    use ReadsFixtures;

    private const string PDS = 'https://pds.example.test';
    private const string UNREAD = 'GET ' . self::PDS . '/xrpc/app.bsky.notification.getUnreadCount';
    private const string LIST = 'GET ' . self::PDS . '/xrpc/app.bsky.notification.listNotifications?limit=50';
    private const string UPDATE_SEEN = 'POST ' . self::PDS . '/xrpc/app.bsky.notification.updateSeen';
    private const string POST_ONE = 'at://did:plc:alice/app.bsky.feed.post/3lpost1';
    private const string POST_TWO = 'at://did:plc:alice/app.bsky.feed.post/3lpost2';

    public function testMapsEveryKeptKind(): void
    {
        $page = $this->source($this->http())->fetch(SocialApiWiring::blueskyAccount());

        self::assertSame(
            ['like', 'like', 'repost', 'repost', 'reply', 'mention', 'quote', 'follow', 'quote'],
            array_map(
                static fn (FetchedNotificationModel $notification): string => $notification->kind->value,
                $page->notifications,
            ),
        );
        $like = $page->notifications[0];
        self::assertSame('at://did:plc:bob/app.bsky.feed.like/3l01', $like->remoteId);
        self::assertSame(self::POST_ONE, $like->subject->groupKey);
        self::assertSame('https://bsky.app/profile/did:plc:alice/post/3lpost1', $like->subject->linkUrl);
        self::assertSame('First post text', $like->subject->excerpt);
        self::assertEquals(
            new NotificationActor(
                'bob.test',
                'Bob',
                'https://cdn.test/bob.jpg',
                'https://bsky.app/profile/did:plc:bob',
            ),
            $like->actor,
        );
        self::assertEquals(new \DateTimeImmutable('2026-11-16 09:00:00', new \DateTimeZone('UTC')), $like->createdAt);
        self::assertSame('Second post text', $page->notifications[2]->subject->excerpt);
    }

    public function testReplyMentionQuoteAndFollowSubjects(): void
    {
        $page = $this->source($this->http())->fetch(SocialApiWiring::blueskyAccount());

        $reply = $page->notifications[4];
        self::assertSame('at://did:plc:frank/app.bsky.feed.post/3l05', $reply->subject->groupKey);
        self::assertSame('https://bsky.app/profile/did:plc:frank/post/3l05', $reply->subject->linkUrl);
        self::assertSame('a reply', $reply->subject->excerpt);
        $follow = $page->notifications[7];
        self::assertEquals(
            new NotificationSubject('', null, 'https://bsky.app/profile/did:plc:ivy'),
            $follow->subject,
        );
    }

    public function testRepliesAndQuotesPointAtWhatTheyAnswer(): void
    {
        $page = $this->source($this->http())->fetch(SocialApiWiring::blueskyAccount());

        self::assertEquals(
            new ContextReferenceModel(
                'at://did:plc:alice/app.bsky.feed.post/3lmine',
                'at://did:plc:zoe/app.bsky.feed.post/3lroot',
            ),
            $page->notifications[4]->references,
        );
        $quoted = new ContextReferenceModel('at://did:plc:alice/app.bsky.feed.post/3lquoted');
        self::assertEquals($quoted, $page->notifications[6]->references);
        self::assertEquals($quoted, $page->notifications[8]->references);
        self::assertEquals(new ContextReferenceModel(), $page->notifications[0]->references);
        self::assertEquals(new ContextReferenceModel(), $page->notifications[5]->references);
    }

    /** @return iterable<string, array{list<array<string, mixed>>, list<bool>}> */
    public static function likedPosts(): iterable
    {
        $first = self::post(self::POST_ONE, 'First post text');
        $second = self::post(self::POST_TWO, 'Second post text');
        $nothing = [false, false, false, false, false, false, false, false, false];

        yield 'two top-level posts' => [[$first, $second], $nothing];
        yield 'a liked reply of yours' => [
            [self::asReply($first), $second],
            [true, true, false, false, false, false, false, false, false],
        ];
        yield 'a reposted reply of yours' => [
            [$first, self::asReply($second)],
            [false, false, true, true, false, false, false, false, false],
        ];
    }

    /**
     * @param list<array<string, mixed>> $posts
     * @param list<bool> $onComment
     */
    #[DataProvider('likedPosts')]
    public function testALikeOrRepostIsAboutYourCommentWhenTheLikedPostIsYourReply(array $posts, array $onComment): void
    {
        $answer = (string) json_encode(['posts' => $posts]);

        $page = $this->source($this->http(posts: $answer))->fetch(SocialApiWiring::blueskyAccount());

        self::assertSame($onComment, array_map(
            static fn (FetchedNotificationModel $notification): bool => $notification->subject->context->onComment,
            $page->notifications,
        ));
        self::assertSame('First post text', $page->notifications[0]->subject->excerpt);
    }

    public function testALikeViaRepostOfSomeoneElsesReplyIsNotAboutYourComment(): void
    {
        $theirs = 'at://did:plc:zoe/app.bsky.feed.post/3lzreply';
        $list = (string) json_encode(['notifications' => [[
            'uri' => 'at://did:plc:carol/app.bsky.feed.like/3l02',
            'author' => ['did' => 'did:plc:carol', 'handle' => 'carol.test'],
            'reason' => 'like-via-repost',
            'record' => ['$type' => 'app.bsky.feed.like', 'subject' => ['uri' => $theirs]],
            'isRead' => false,
            'indexedAt' => '2026-11-16T08:50:00.000Z',
        ]]]);
        $posts = (string) json_encode(['posts' => [[
            'uri' => $theirs,
            'record' => [
                'text' => 'Their reply',
                'reply' => ['parent' => ['uri' => 'at://did:plc:zoe/app.bsky.feed.post/3lz']],
            ],
        ]]]);

        $page = $this->source($this->http($list, $posts, [$theirs]))->fetch(SocialApiWiring::blueskyAccount());

        self::assertSame('Their reply', $page->notifications[0]->subject->excerpt);
        self::assertFalse($page->notifications[0]->subject->context->onComment);
    }

    public function testAsksOnePostLookupWithRepeatedUrisKeys(): void
    {
        $http = $this->http();
        $this->source($http)->fetch(SocialApiWiring::blueskyAccount());

        self::assertContains(
            'GET ' . self::PDS . '/xrpc/app.bsky.feed.getPosts?uris=' . rawurlencode(self::POST_ONE)
                . '&uris=' . rawurlencode(self::POST_TWO),
            $http->requestLines(),
        );
    }

    public function testDropsUnlistedKinds(): void
    {
        $page = $this->source($this->http())->fetch(SocialApiWiring::blueskyAccount());

        foreach ($page->notifications as $notification) {
            self::assertStringNotContainsString('did:plc:jon', $notification->remoteId);
        }
        self::assertCount(9, $page->notifications);
    }

    public function testSeenComesFromTheNetwork(): void
    {
        $page = $this->source($this->http())->fetch(SocialApiWiring::blueskyAccount());

        self::assertSame(
            [false, false, false, true, true, true, true, true, true],
            array_map(
                static fn (FetchedNotificationModel $notification): bool => $notification->seen,
                $page->notifications,
            ),
        );
        self::assertEquals(new \DateTimeImmutable('2026-11-16 08:30:00', new \DateTimeZone('UTC')), $page->seenUntil);
    }

    public function testZeroUnreadSkipsTheList(): void
    {
        $http = RoutedHttpClient::answering(static fn (string $method, string $url): MockResponse => match (true) {
            str_contains($url, 'createSession') => SocialApiWiring::blueskySessionAnswer('jwt'),
            str_contains($url, 'getUnreadCount') => new MockResponse('{"count":0}'),
            default => self::fail('Unexpected request: ' . $url),
        });

        $page = $this->source($http)->fetch(SocialApiWiring::blueskyAccount());

        self::assertSame([], $page->notifications);
        self::assertEquals(new \DateTimeImmutable('2026-11-16 12:00:00', new \DateTimeZone('UTC')), $page->seenUntil);
        self::assertCount(2, $http->requests);
    }

    public function testMarkSeenSendsTheNewestMarker(): void
    {
        $http = RoutedHttpClient::answering(static fn (string $method, string $url): MockResponse => match (true) {
            str_contains($url, 'createSession') => SocialApiWiring::blueskySessionAnswer('jwt'),
            str_contains($url, 'updateSeen') => new MockResponse('{}'),
            default => self::fail('Unexpected request: ' . $url),
        });
        $account = SocialApiWiring::blueskyAccount();
        $older = self::stored($account, 'a', '2026-11-16 08:00:00');
        $newer = self::stored($account, 'b', '2026-11-16 09:30:00');
        $marked = [];

        $this->source($http)->markSeen(
            $account,
            [$older, $newer],
            static function (SocialNotification $row) use (&$marked): void {
                $marked[] = $row->getRemoteId();
            },
        );

        $update = $http->requests[1];
        self::assertSame('POST', $update['method']);
        self::assertSame(self::UPDATE_SEEN, 'POST ' . $update['url']);
        self::assertSame(['seenAt' => '2026-11-16T09:30:00.999Z'], json_decode($update['body'], true));
        self::assertSame(['a', 'b'], $marked);
    }

    public function testTheMarkerCoversTheMillisecondsTheStoredSecondLost(): void
    {
        $http = RoutedHttpClient::answering(static fn (string $method, string $url): MockResponse => match (true) {
            str_contains($url, 'createSession') => SocialApiWiring::blueskySessionAnswer('jwt'),
            str_contains($url, 'updateSeen') => new MockResponse('{}'),
            default => self::fail('Unexpected request: ' . $url),
        });
        $account = SocialApiWiring::blueskyAccount();
        $indexedAt = new \DateTimeImmutable('2026-11-16T09:30:00.750Z');
        $stored = self::stored($account, 'a', $indexedAt->format('Y-m-d H:i:s'));

        $this->source($http)->markSeen($account, [$stored], static function (): void {
        });

        $body = JsonNodeModel::of(json_decode($http->requests[1]['body'], true));
        self::assertGreaterThanOrEqual($indexedAt, new \DateTimeImmutable((string) $body->string('seenAt')));
    }

    #[DataProvider('refusedStatuses')]
    public function testMarkSeenRefusalIsUnreachable(int $status): void
    {
        $http = RoutedHttpClient::answering(static fn (string $method, string $url): MockResponse => match (true) {
            str_contains($url, 'createSession') => SocialApiWiring::blueskySessionAnswer('jwt'),
            str_contains($url, 'updateSeen') => new MockResponse(
                '{"error":"InvalidRequest"}',
                ['http_code' => $status],
            ),
            default => self::fail('Unexpected request: ' . $url),
        });
        $account = SocialApiWiring::blueskyAccount();
        $marked = 0;

        try {
            $this->source($http)->markSeen(
                $account,
                [self::stored($account, 'a', '2026-11-16 08:00:00')],
                static function () use (&$marked): void {
                    ++$marked;
                },
            );
            self::fail('A refused marker must not pass.');
        } catch (SocialNetworkUnreachableException $exception) {
            self::assertNotNull($exception->getPrevious());
            self::assertSame(0, $marked);
        }
    }

    /** @return iterable<string, array{int}> */
    public static function refusedStatuses(): iterable
    {
        yield 'bad request' => [Response::HTTP_BAD_REQUEST];
        yield 'not found' => [Response::HTTP_NOT_FOUND];
    }

    private static function stored(SocialAccount $account, string $remoteId, string $createdAt): SocialNotification
    {
        return new SocialNotification(
            $account,
            $remoteId,
            SocialNotificationKind::Follow,
            new NotificationActor('bob.test', null, null, 'https://bsky.app/profile/did:plc:bob'),
            new NotificationSubject('', null, 'https://bsky.app/profile/did:plc:bob'),
            new \DateTimeImmutable($createdAt, new \DateTimeZone('UTC')),
            false,
        );
    }

    /** @return array{uri: string, record: array<string, mixed>} */
    private static function post(string $uri, string $text): array
    {
        return ['uri' => $uri, 'record' => ['text' => $text]];
    }

    /**
     * @param array{uri: string, record: array<string, mixed>} $post
     *
     * @return array{uri: string, record: array<string, mixed>}
     */
    private static function asReply(array $post): array
    {
        $post['record']['reply'] = ['parent' => ['uri' => 'at://did:plc:zoe/app.bsky.feed.post/3lz']];

        return $post;
    }

    private function list(): string
    {
        return $this->fixture('Bluesky/notifications-list.json');
    }

    /** @param list<string> $postUris */
    private function http(
        ?string $list = null,
        ?string $posts = null,
        array $postUris = [self::POST_ONE, self::POST_TWO],
    ): RoutedHttpClient {
        $lookup = implode('&', array_map(static fn (string $uri): string => 'uris=' . rawurlencode($uri), $postUris));

        return new RoutedHttpClient([
            'POST ' . self::PDS . '/xrpc/com.atproto.server.createSession'
                => SocialApiWiring::blueskySessionAnswer('jwt'),
            self::UNREAD => new MockResponse($this->fixture('Bluesky/notifications-unread.json')),
            self::LIST => new MockResponse($list ?? $this->list()),
            'GET ' . self::PDS . '/xrpc/app.bsky.feed.getPosts?' . $lookup => new MockResponse(
                $posts ?? $this->fixture('Bluesky/notifications-posts.json'),
            ),
        ]);
    }

    private function source(RoutedHttpClient $http): BlueskyNotificationSource
    {
        $sessions = SocialApiWiring::blueskySessions($http->client());

        return new BlueskyNotificationSource(
            $sessions,
            new BlueskyPostLookup($sessions),
            new NaiveUtcClock(new MockClock('2026-11-16 12:00:00', 'UTC')),
        );
    }
}
