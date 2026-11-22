<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Mastodon;

use App\Entity\NotificationActor;
use App\Entity\NotificationSubject;
use App\Entity\SocialAccount;
use App\Entity\SocialNotification;
use App\Enum\SocialNotificationKind;
use App\Service\Social\Mastodon\MastodonNotificationSource;
use App\Service\Social\Mastodon\Support\MastodonStatusKey;
use App\Service\Social\Notification\Model\ContextReferenceModel;
use App\Service\Social\Notification\Model\FetchedNotificationModel;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialTokenScopeMissingException;
use App\Tests\Support\ReadsFixtures;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class MastodonNotificationSourceTest extends TestCase
{
    use ReadsFixtures;

    private const string FIXTURES = 'Mastodon/';
    private const string INSTANCE = 'https://mastodon.example';
    private const string VERIFY = self::INSTANCE . '/api/v1/accounts/verify_credentials';
    private const string MARKERS = self::INSTANCE . '/api/v1/markers?timeline[]=notifications';
    private const string LIST = self::INSTANCE . '/api/v1/notifications?limit=50&types[]=mention&types[]=favourite'
        . '&types[]=reblog&types[]=follow&types[]=poll&types[]=quote';
    private const string MARKER_ITEM = self::INSTANCE . '/api/v1/notifications/1075';
    private const string MARKER_WRITE = self::INSTANCE . '/api/v1/markers';

    public function testMapsEveryKeptKind(): void
    {
        $page = $this->source($this->http())->fetch(SocialApiWiring::mastodonAccount());

        self::assertSame(
            ['like', 'repost', 'reply', 'mention', 'follow', 'poll', 'quote'],
            array_map(
                static fn (FetchedNotificationModel $notification): string => $notification->kind->value,
                $page->notifications,
            ),
        );
        $like = $page->notifications[0];
        self::assertSame('1100', $like->remoteId);
        self::assertSame('https://mastodon.example/users/alice/statuses/1100', $like->subject->groupKey);
        self::assertSame('https://mastodon.example/@alice/1100', $like->subject->linkUrl);
        self::assertSame('First post second para', $like->subject->excerpt);
        self::assertEquals(
            new NotificationActor('bob', 'Bob', 'https://cdn.example/bob.png', 'https://mastodon.example/@bob'),
            $like->actor,
        );
        self::assertEquals(new \DateTimeImmutable('2026-11-16 09:00:00', new \DateTimeZone('UTC')), $like->createdAt);
        self::assertNull($page->notifications[1]->actor->displayName);
        $follow = $page->notifications[4];
        self::assertEquals(
            new NotificationSubject('', null, 'https://mastodon.example/@bob@other.example'),
            $follow->subject,
        );
        self::assertSame('1030', $page->notifications[6]->subject->groupKey);
    }

    public function testALikeCarriesTheLikedStatusKey(): void
    {
        $like = $this->source($this->http())->fetch(SocialApiWiring::mastodonAccount())->notifications[0];

        self::assertSame(
            MastodonStatusKey::parseEntryKey('https://mastodon.example/@alice/1100', null)?->keyHash(),
            $like->subject->keys->postHash,
        );
        self::assertNull($like->subject->keys->answeredHash);
        self::assertNull($like->subject->keys->rootHash);
    }

    public function testARepostAndAPollCarryTheirStatusKey(): void
    {
        $notifications = $this->source($this->http())->fetch(SocialApiWiring::mastodonAccount())->notifications;

        self::assertSame(
            MastodonStatusKey::parseEntryKey('https://mastodon.example/users/alice/statuses/1090', null)?->keyHash(),
            $notifications[1]->subject->keys->postHash,
        );
        self::assertSame(
            MastodonStatusKey::parseEntryKey('https://mastodon.example/users/alice/statuses/1040', null)?->keyHash(),
            $notifications[5]->subject->keys->postHash,
        );
    }

    public function testAReplyAndAMentionCarryTheirOwnStatusKey(): void
    {
        $notifications = $this->source($this->http())->fetch(SocialApiWiring::mastodonAccount())->notifications;

        self::assertSame(
            MastodonStatusKey::parseEntryKey('https://other.example/users/dave/statuses/5001', null)?->keyHash(),
            $notifications[2]->subject->keys->postHash,
        );
        self::assertNull($notifications[2]->subject->keys->answeredHash);
        self::assertSame(
            MastodonStatusKey::parseEntryKey('https://other.example/users/erin/statuses/5002', null)?->keyHash(),
            $notifications[3]->subject->keys->postHash,
        );
    }

    public function testAQuoteCarriesTheQuotedStatusAsAnswered(): void
    {
        $keys = $this->source($this->http())->fetch(SocialApiWiring::mastodonAccount())->notifications[6]
            ->subject->keys;

        self::assertSame(
            MastodonStatusKey::parseEntryKey('https://other.example/users/gus/statuses/5003', null)?->keyHash(),
            $keys->postHash,
        );
        self::assertSame(
            MastodonStatusKey::parseEntryKey('https://mastodon.example/users/alice/statuses/4001', null)?->keyHash(),
            $keys->answeredHash,
        );
    }

    public function testAStatusOnAnApAddressCarriesTheKeyOfItsPage(): void
    {
        $http = $this->http(listBody: $this->fixture(self::FIXTURES . 'notifications-ap-uri.json'));

        $notifications = $this->source($http)->fetch(SocialApiWiring::mastodonAccount())->notifications;

        self::assertSame(
            MastodonStatusKey::parseEntryKey('https://mastodon.example/@alice/7001', null)?->keyHash(),
            $notifications[0]->subject->keys->postHash,
        );
        self::assertSame(
            MastodonStatusKey::parseEntryKey('https://mastodon.example/@alice/7002', null)?->keyHash(),
            $notifications[1]->subject->keys->answeredHash,
        );
    }

    public function testAStatusWithoutAnAddressCarriesNoKey(): void
    {
        $author = ['id' => '109', 'acct' => 'alice'];
        $actor = ['id' => '201', 'acct' => 'bob'];
        $list = (string) json_encode([
            [
                'id' => '1300',
                'type' => 'quote',
                'created_at' => '2026-11-16T09:00:00.000Z',
                'account' => $actor,
                'status' => [
                    'id' => '7010',
                    'content' => '<p>quoting</p>',
                    'account' => $actor,
                    'quote' => ['state' => 'accepted', 'quoted_status' => ['id' => '7011', 'account' => $author]],
                ],
            ],
        ]);

        $keys = $this->source($this->http(listBody: $list))->fetch(SocialApiWiring::mastodonAccount())
            ->notifications[0]->subject->keys;

        self::assertNull($keys->postHash);
        self::assertNull($keys->answeredHash);
    }

    public function testAFollowCarriesNoKeys(): void
    {
        $follow = $this->source($this->http())->fetch(SocialApiWiring::mastodonAccount())->notifications[4];

        self::assertSame([], $follow->subject->keys->hashes());
    }

    public function testAReplyPointsAtThePostItAnswers(): void
    {
        $page = $this->source($this->http())->fetch(SocialApiWiring::mastodonAccount());

        self::assertSame('4000', $page->notifications[2]->references->answeredId);
        self::assertEquals(new ContextReferenceModel(), $page->notifications[3]->references);
    }

    public function testAQuoteCarriesTheQuotedPost(): void
    {
        $quote = $this->source($this->http())->fetch(SocialApiWiring::mastodonAccount())->notifications[6];

        self::assertSame('The post they quoted', $quote->subject->context->answeredExcerpt);
        self::assertSame('quoting', $quote->subject->excerpt);
    }

    /** @return iterable<string, array{string, int}> */
    public static function repliesOfYours(): iterable
    {
        yield 'a favourite of your reply' => ['"content": "<p>First post</p><p>second para</p>",', 0];
        yield 'a boost of your reply' => ['"content": "<p>Boosted</p>",', 1];
        yield 'a quote of your reply' => ['"content": "<p>The post they quoted</p>",', 6];
    }

    #[DataProvider('repliesOfYours')]
    public function testANotificationAboutYourReplyIsAboutYourComment(string $statusContent, int $index): void
    {
        $list = str_replace($statusContent, $statusContent . ' "in_reply_to_id": "777",', $this->list(), $patched);
        self::assertSame(1, $patched);

        $page = $this->source($this->http(listBody: $list))->fetch(SocialApiWiring::mastodonAccount());

        $onComment = array_fill(0, count($page->notifications), false);
        $onComment[$index] = true;
        self::assertSame($onComment, array_map(
            static fn (FetchedNotificationModel $notification): bool => $notification->subject->context->onComment,
            $page->notifications,
        ));
    }

    public function testNotificationsAboutTopLevelPostsAreAboutYourPost(): void
    {
        $page = $this->source($this->http())->fetch(SocialApiWiring::mastodonAccount());

        foreach ($page->notifications as $notification) {
            self::assertFalse($notification->subject->context->onComment);
        }
    }

    public function testDropsUnlistedKinds(): void
    {
        $page = $this->source($this->http())->fetch(SocialApiWiring::mastodonAccount());

        self::assertNotContains('1050', array_map(
            static fn (FetchedNotificationModel $notification): string => $notification->remoteId,
            $page->notifications,
        ));
        self::assertCount(7, $page->notifications);
    }

    public function testReplyToOwnAccountIsReply(): void
    {
        $page = $this->source($this->http())->fetch(SocialApiWiring::mastodonAccount());

        self::assertSame(SocialNotificationKind::Reply, $page->notifications[2]->kind);
        self::assertSame(SocialNotificationKind::Mention, $page->notifications[3]->kind);
        self::assertSame('1080', $page->notifications[2]->subject->groupKey);
    }

    public function testSeenComesFromTheNetwork(): void
    {
        $http = $this->http();
        $page = $this->source($http)->fetch(SocialApiWiring::mastodonAccount());

        self::assertSame(
            [false, false, false, true, true, true, true],
            array_map(
                static fn (FetchedNotificationModel $notification): bool => $notification->seen,
                $page->notifications,
            ),
        );
        self::assertEquals(new \DateTimeImmutable('2026-11-16 08:30:00', new \DateTimeZone('UTC')), $page->seenUntil);
        self::assertContains('GET ' . self::MARKER_ITEM, $http->requestLines());
    }

    public function testNoMarkerMeansNothingSeen(): void
    {
        $http = $this->http(markers: '{}');
        $page = $this->source($http)->fetch(SocialApiWiring::mastodonAccount());

        self::assertNull($page->seenUntil);
        self::assertNotContains(true, array_map(
            static fn (FetchedNotificationModel $notification): bool => $notification->seen,
            $page->notifications,
        ));
        self::assertNotContains('GET ' . self::MARKER_ITEM, $http->requestLines());
    }

    public function testMarkerItemGoneMeansNoSeenUntil(): void
    {
        $page = $this->source($this->http(markerItemStatus: Response::HTTP_NOT_FOUND))
            ->fetch(SocialApiWiring::mastodonAccount());

        self::assertNull($page->seenUntil);
    }

    public function testMarkerItemOnThePageNeedsNoLookup(): void
    {
        $http = $this->http(markers: '{"notifications":{"last_read_id":"1060"}}');
        $page = $this->source($http)->fetch(SocialApiWiring::mastodonAccount());

        self::assertEquals(new \DateTimeImmutable('2026-11-16 08:10:00', new \DateTimeZone('UTC')), $page->seenUntil);
        self::assertCount(3, $http->requests);
    }

    public function testMarkerItemRefusedIsUnreachable(): void
    {
        $http = $this->http(markerItemStatus: Response::HTTP_FORBIDDEN);

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->source($http)->fetch(SocialApiWiring::mastodonAccount());
    }

    public function testListNotFoundIsUnreachable(): void
    {
        $http = $this->http(listStatus: Response::HTTP_NOT_FOUND);

        try {
            $this->source($http)->fetch(SocialApiWiring::mastodonAccount());
            self::fail('A missing list must not pass.');
        } catch (SocialNetworkUnreachableException $exception) {
            self::assertNotNull($exception->getPrevious());
        }
    }

    public function testListRefusedIsUnreachable(): void
    {
        $http = $this->http(listStatus: Response::HTTP_FORBIDDEN, listBody: '{"error":"Forbidden"}');

        try {
            $this->source($http)->fetch(SocialApiWiring::mastodonAccount());
            self::fail('A refused list must not pass.');
        } catch (SocialNetworkUnreachableException $exception) {
            self::assertNotNull($exception->getPrevious());
        }
    }

    public function testRejectedTokenSurfaces(): void
    {
        $http = $this->http(verifyStatus: Response::HTTP_UNAUTHORIZED);

        $this->expectException(SocialCredentialsRejectedException::class);

        $this->source($http)->fetch(SocialApiWiring::mastodonAccount());
    }

    public function testAnswerWithoutAccountIdIsUnreachable(): void
    {
        $http = $this->http(verifyBody: '{}');

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->source($http)->fetch(SocialApiWiring::mastodonAccount());
    }

    public function testScopeRefusalThrowsScopeMissing(): void
    {
        $http = $this->http(listStatus: Response::HTTP_FORBIDDEN);

        $this->expectException(SocialTokenScopeMissingException::class);

        $this->source($http)->fetch(SocialApiWiring::mastodonAccount());
    }

    public function testMarkSeenSendsTheNewestMarker(): void
    {
        $http = RoutedHttpClient::answering(static fn (string $method, string $url): MockResponse => match (true) {
            'POST ' . self::MARKER_WRITE === $method . ' ' . $url => new MockResponse('{}'),
            default => self::fail('Unexpected request: ' . $url),
        });
        $account = SocialApiWiring::mastodonAccount();
        $marked = [];

        $this->source($http)->markSeen(
            $account,
            [self::stored($account, '99'), self::stored($account, '100')],
            static function (SocialNotification $row) use (&$marked): void {
                $marked[] = $row->getRemoteId();
            },
        );

        self::assertSame(['notifications' => ['last_read_id' => '100']], json_decode($http->requests[0]['body'], true));
        self::assertSame(['99', '100'], $marked);
    }

    public function testMarkSeenRefusalIsUnreachable(): void
    {
        $http = RoutedHttpClient::answering(static fn (): MockResponse => new MockResponse(
            '{"error":"Validation failed"}',
            ['http_code' => Response::HTTP_UNPROCESSABLE_ENTITY],
        ));
        $account = SocialApiWiring::mastodonAccount();
        $marked = 0;

        try {
            $this->source($http)->markSeen(
                $account,
                [self::stored($account, '99')],
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

    private static function stored(SocialAccount $account, string $remoteId): SocialNotification
    {
        return new SocialNotification(
            $account,
            $remoteId,
            SocialNotificationKind::Follow,
            new NotificationActor('bob', null, null, 'https://mastodon.example/@bob'),
            new NotificationSubject('', null, 'https://mastodon.example/@bob'),
            new \DateTimeImmutable('2026-11-16 08:00:00', new \DateTimeZone('UTC')),
            false,
        );
    }

    private function list(): string
    {
        return $this->fixture(self::FIXTURES . 'notifications-list.json');
    }

    private function http(
        ?string $markers = null,
        int $listStatus = Response::HTTP_OK,
        int $markerItemStatus = Response::HTTP_OK,
        int $verifyStatus = Response::HTTP_OK,
        ?string $verifyBody = null,
        ?string $listBody = null,
    ): RoutedHttpClient {
        $forbidden = '{"error":"This action is outside the authorized scopes"}';

        return new RoutedHttpClient([
            'GET ' . self::VERIFY => new MockResponse(
                $verifyBody ?? $this->fixture(self::FIXTURES . 'verify-credentials-notifications.json'),
                ['http_code' => $verifyStatus],
            ),
            'GET ' . self::MARKERS => new MockResponse(
                $markers ?? $this->fixture(self::FIXTURES . 'notifications-markers.json'),
            ),
            'GET ' . self::LIST => new MockResponse(
                $listBody ?? ($listStatus === Response::HTTP_OK ? $this->list() : $forbidden),
                ['http_code' => $listStatus],
            ),
            'GET ' . self::MARKER_ITEM => new MockResponse(
                $markerItemStatus === Response::HTTP_OK
                    ? $this->fixture(self::FIXTURES . 'notifications-marker-item.json')
                    : '{"error":"Record not found"}',
                ['http_code' => $markerItemStatus],
            ),
        ]);
    }

    private function source(RoutedHttpClient $http): MastodonNotificationSource
    {
        return new MastodonNotificationSource(SocialApiWiring::mastodonAccountApi($http->client()));
    }
}
