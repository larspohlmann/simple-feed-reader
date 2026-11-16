<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Notification\NotificationSource;

use App\Entity\NotificationActor;
use App\Entity\NotificationSubject;
use App\Entity\SocialAccount;
use App\Entity\SocialNotification;
use App\Enum\SocialNotificationKind;
use App\Service\Social\Notification\Model\FetchedNotificationModel;
use App\Service\Social\Notification\NotificationSource\MastodonNotificationSource;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialTokenScopeMissingException;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class MastodonNotificationSourceTest extends TestCase
{
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

    private function http(
        ?string $markers = null,
        int $listStatus = Response::HTTP_OK,
        int $markerItemStatus = Response::HTTP_OK,
    ): RoutedHttpClient {
        $fixtures = __DIR__ . '/../../../../Fixtures/Mastodon/';
        $forbidden = '{"error":"This action is outside the authorized scopes"}';

        return new RoutedHttpClient([
            'GET ' . self::VERIFY => new MockResponse(
                (string) file_get_contents($fixtures . 'verify-credentials-notifications.json'),
            ),
            'GET ' . self::MARKERS => new MockResponse(
                $markers ?? (string) file_get_contents($fixtures . 'notifications-markers.json'),
            ),
            'GET ' . self::LIST => new MockResponse(
                $listStatus === Response::HTTP_OK
                    ? (string) file_get_contents($fixtures . 'notifications-list.json')
                    : $forbidden,
                ['http_code' => $listStatus],
            ),
            'GET ' . self::MARKER_ITEM => new MockResponse(
                $markerItemStatus === Response::HTTP_OK
                    ? (string) file_get_contents($fixtures . 'notifications-marker-item.json')
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
