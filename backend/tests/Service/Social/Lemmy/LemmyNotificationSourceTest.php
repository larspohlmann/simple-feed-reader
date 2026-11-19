<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Lemmy;

use App\Entity\NotificationActor;
use App\Entity\NotificationContext;
use App\Entity\NotificationSubject;
use App\Entity\SocialAccount;
use App\Entity\SocialNotification;
use App\Enum\SocialNotificationKind;
use App\Service\Social\Notification\Model\ContextReferenceModel;
use App\Service\Social\Notification\Model\FetchedNotificationModel;
use App\Service\Social\Lemmy\LemmyNotificationSource;
use App\Service\Social\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Tests\Support\ReadsFixtures;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class LemmyNotificationSourceTest extends TestCase
{
    use ReadsFixtures;

    private const string INSTANCE = 'https://lemmy.example';
    private const string REPLIES_FIXTURE = 'lemmy/notifications-replies.json';
    private const string REPLIES = self::INSTANCE . '/api/v3/user/replies?sort=New&limit=50&unread_only=false';
    private const string MENTIONS = self::INSTANCE . '/api/v3/user/mention?sort=New&limit=50&unread_only=false';
    private const string MARK_REPLY = self::INSTANCE . '/api/v3/comment/mark_as_read';
    private const string MARK_MENTION = self::INSTANCE . '/api/v3/user/mention/mark_as_read';

    public function testMapsRepliesAndMentions(): void
    {
        $page = $this->source($this->http())->fetch(SocialApiWiring::lemmyAccount());

        self::assertSame(
            ['reply:7', 'reply:6', 'mention:3'],
            array_map(
                static fn (FetchedNotificationModel $notification): string => $notification->remoteId,
                $page->notifications,
            ),
        );
        $reply = $page->notifications[0];
        self::assertSame(SocialNotificationKind::Reply, $reply->kind);
        self::assertSame('26906403', $reply->references->answeredId);
        self::assertEquals(
            new NotificationActor(
                'carol@lemmy.other',
                'Carol',
                'https://lemmy.other/pictrs/image/carol.png',
                'https://lemmy.example/u/carol@lemmy.other',
            ),
            $reply->actor,
        );
        self::assertEquals(
            new NotificationSubject(
                'reply:7',
                'Thanks, that helped a lot',
                'https://lemmy.example/comment/41',
                new NotificationContext(
                    threadTitle: 'Which self-hosted reader do you use?',
                    threadPlace: 'c/selfhosted',
                    onComment: true,
                ),
            ),
            $reply->subject,
        );
        self::assertEquals(self::utc('2026-11-16 09:00:00.123456'), $reply->createdAt);
        self::assertNull($page->notifications[1]->subject->excerpt);
        self::assertSame(SocialNotificationKind::Reply, $page->notifications[1]->kind);
        self::assertEquals(new ContextReferenceModel(), $page->notifications[1]->references);
        self::assertSame(
            'Which self-hosted reader do you use?',
            $page->notifications[1]->subject->context->threadTitle,
        );
        self::assertSame('c/selfhosted', $page->notifications[1]->subject->context->threadPlace);
        self::assertFalse($page->notifications[1]->subject->context->onComment);
        self::assertEquals(self::utc('2026-11-16 08:00:00'), $page->notifications[1]->createdAt);
        $mention = $page->notifications[2];
        self::assertSame(SocialNotificationKind::Mention, $mention->kind);
        self::assertSame('mention:3', $mention->subject->groupKey);
        self::assertSame('c/askme', $mention->subject->context->threadPlace);
        self::assertSame('Weekly question thread', $mention->subject->context->threadTitle);
        self::assertFalse($mention->subject->context->onComment);
        self::assertSame('https://lemmy.example/comment/55', $mention->subject->linkUrl);
        self::assertSame('dave@lemmy.example', $mention->actor->handle);
        self::assertNull($mention->actor->avatarUrl);
        self::assertEquals(self::utc('2026-11-16 07:30:00'), $mention->createdAt);
    }

    public function testAReplyToYourCommentKeepsTheReplyRemoteId(): void
    {
        $page = $this->source($this->http())->fetch(SocialApiWiring::lemmyAccount());

        self::assertSame('reply:7', $page->notifications[0]->remoteId);
        self::assertSame('reply:7', $page->notifications[0]->subject->groupKey);
    }

    public function testAQuotedLineAndALinkReadAsPlainText(): void
    {
        $replies = $this->fixture(self::REPLIES_FIXTURE);
        $markdown = json_encode("> you said this\n\nSee [the docs](https://example.com) **now**");
        $quoting = str_replace('"Thanks, **that** helped a lot"', (string) $markdown, $replies);

        $page = $this->source($this->http($quoting))->fetch(SocialApiWiring::lemmyAccount());

        self::assertSame('you said this See the docs now', $page->notifications[0]->subject->excerpt);
    }

    public function testAMissingCommunityLeavesNoPlace(): void
    {
        $replies = $this->fixture(self::REPLIES_FIXTURE);
        $community = '/"community": \{"id": 4, "name": "selfhosted"\}/';
        $broken = preg_replace($community, '"community": {"id": 4}', $replies, 1);

        $page = $this->source($this->http((string) $broken))->fetch(SocialApiWiring::lemmyAccount());

        self::assertNull($page->notifications[0]->subject->context->threadPlace);
        self::assertSame('c/selfhosted', $page->notifications[1]->subject->context->threadPlace);
    }

    public function testSeenComesFromTheNetwork(): void
    {
        $page = $this->source($this->http())->fetch(SocialApiWiring::lemmyAccount());

        self::assertSame(
            [false, true, false],
            array_map(
                static fn (FetchedNotificationModel $notification): bool => $notification->seen,
                $page->notifications,
            ),
        );
        self::assertNull($page->seenUntil);
    }

    /** @return iterable<string, array{string, string}> the first reply's field, and what it becomes */
    public static function malformedReplies(): iterable
    {
        yield 'no reply id' => ['"comment_reply": {"id": 7,', '"comment_reply": {'];
        yield 'no comment id' => ['"comment": {"id": 41,', '"comment": {'];
        yield 'no published' => ['"published": "2026-11-16T09:00:00.123456Z"', '"edited": true'];
        yield 'unparseable published' => ['"published": "2026-11-16T09:00:00.123456Z"', '"published": "never"'];
        yield 'no creator name' => ['"name": "carol"', '"nick": "carol"'];
        yield 'no creator actor id' => ['"actor_id": "https://lemmy.other/u/carol"', '"home": "lemmy.other"'];
    }

    #[DataProvider('malformedReplies')]
    public function testDropsEntriesMissingWhatTheRowNeeds(string $field, string $replacement): void
    {
        $replies = $this->fixture(self::REPLIES_FIXTURE);
        $broken = preg_replace('/' . preg_quote($field, '/') . '/', $replacement, $replies, 1);
        $page = $this->source($this->http((string) $broken))->fetch(SocialApiWiring::lemmyAccount());

        self::assertSame(
            ['reply:6', 'mention:3'],
            array_map(
                static fn (FetchedNotificationModel $notification): string => $notification->remoteId,
                $page->notifications,
            ),
        );
    }

    public function testNeverAsksForPrivateMessages(): void
    {
        $http = $this->http();

        $this->source($http)->fetch(SocialApiWiring::lemmyAccount());

        self::assertSame(['GET ' . self::REPLIES, 'GET ' . self::MENTIONS], $http->requestLines());
        foreach ($http->requestedUrls() as $url) {
            self::assertStringNotContainsString('private_message', $url);
        }
    }

    public function testRefusedListIsUnreachable(): void
    {
        $http = RoutedHttpClient::answering(static fn (): MockResponse => new MockResponse(
            '{"error":"person_is_banned_from_site"}',
            ['http_code' => Response::HTTP_BAD_REQUEST],
        ));

        try {
            $this->source($http)->fetch(SocialApiWiring::lemmyAccount());
            self::fail('A refused list must not pass.');
        } catch (SocialNetworkUnreachableException $exception) {
            self::assertNotNull($exception->getPrevious());
        }
    }

    public function testMarkSeenMarksEachItemAndReportsEach(): void
    {
        $http = $this->markingHttp();
        $account = SocialApiWiring::lemmyAccount();
        $marked = [];

        $this->source($http)->markSeen(
            $account,
            self::storedPair($account),
            static function (SocialNotification $row) use (&$marked): void {
                $marked[] = $row->getRemoteId();
            },
        );

        self::assertSame(['POST ' . self::MARK_REPLY, 'POST ' . self::MARK_MENTION], $http->requestLines());
        self::assertSame('{"comment_reply_id":7,"read":true}', $http->requests[0]['body']);
        self::assertSame('{"person_mention_id":3,"read":true}', $http->requests[1]['body']);
        self::assertSame(['reply:7', 'mention:3'], $marked);
    }

    public function testThrottleStopsTheMarking(): void
    {
        $http = $this->markingHttp(mentionStatus: Response::HTTP_TOO_MANY_REQUESTS);
        $account = SocialApiWiring::lemmyAccount();
        $marked = 0;

        try {
            $this->source($http)->markSeen(
                $account,
                self::storedPair($account),
                static function () use (&$marked): void {
                    ++$marked;
                },
            );
            self::fail('A throttled marking must not pass.');
        } catch (SocialNetworkThrottledException) {
            self::assertSame(1, $marked);
        }
    }

    public function testRefusedMarkingIsUnreachable(): void
    {
        $http = RoutedHttpClient::answering(static fn (): MockResponse => new MockResponse(
            '{"error":"person_is_banned_from_site"}',
            ['http_code' => Response::HTTP_BAD_REQUEST],
        ));
        $account = SocialApiWiring::lemmyAccount();

        try {
            $this->source($http)->markSeen(
                $account,
                [self::stored($account, 'reply:7', SocialNotificationKind::Reply)],
                static function (): void {
                },
            );
            self::fail('A refused marking must not pass.');
        } catch (SocialNetworkUnreachableException $exception) {
            self::assertNotNull($exception->getPrevious());
        }
    }

    public function testAGoneReplyIsMarkedAndTheRestFollow(): void
    {
        $http = $this->markingHttp(replyStatus: Response::HTTP_NOT_FOUND);
        $account = SocialApiWiring::lemmyAccount();
        $marked = [];

        $this->source($http)->markSeen(
            $account,
            self::storedPair($account),
            static function (SocialNotification $row) use (&$marked): void {
                $marked[] = $row->getRemoteId();
            },
        );

        self::assertSame(['reply:7', 'mention:3'], $marked);
        self::assertCount(2, $http->requests);
    }

    public function testAGoneMentionIsMarkedAndTheRestFollow(): void
    {
        $http = $this->markingHttp(mentionStatus: Response::HTTP_NOT_FOUND);
        $account = SocialApiWiring::lemmyAccount();
        $marked = [];

        $this->source($http)->markSeen(
            $account,
            [
                self::stored($account, 'mention:3', SocialNotificationKind::Mention),
                self::stored($account, 'reply:7', SocialNotificationKind::Reply),
            ],
            static function (SocialNotification $row) use (&$marked): void {
                $marked[] = $row->getRemoteId();
            },
        );

        self::assertSame(['mention:3', 'reply:7'], $marked);
        self::assertCount(2, $http->requests);
    }

    private static function utc(string $timestamp): \DateTimeImmutable
    {
        return new \DateTimeImmutable($timestamp, new \DateTimeZone('UTC'));
    }

    /** @return non-empty-list<SocialNotification> */
    private static function storedPair(SocialAccount $account): array
    {
        return [
            self::stored($account, 'reply:7', SocialNotificationKind::Reply),
            self::stored($account, 'mention:3', SocialNotificationKind::Mention),
        ];
    }

    private static function stored(
        SocialAccount $account,
        string $remoteId,
        SocialNotificationKind $kind,
    ): SocialNotification {
        return new SocialNotification(
            $account,
            $remoteId,
            $kind,
            new NotificationActor('carol@lemmy.other', null, null, 'https://lemmy.example/u/carol@lemmy.other'),
            new NotificationSubject($remoteId, null, 'https://lemmy.example/comment/41'),
            new \DateTimeImmutable('2026-11-16 08:00:00', new \DateTimeZone('UTC')),
            false,
        );
    }

    private function http(?string $replies = null): RoutedHttpClient
    {
        return new RoutedHttpClient([
            'GET ' . self::REPLIES => new MockResponse(
                $replies ?? $this->fixture(self::REPLIES_FIXTURE),
            ),
            'GET ' . self::MENTIONS => new MockResponse(
                $this->fixture('lemmy/notifications-mentions.json'),
            ),
        ]);
    }

    private function markingHttp(
        int $replyStatus = Response::HTTP_OK,
        int $mentionStatus = Response::HTTP_OK,
    ): RoutedHttpClient {
        return new RoutedHttpClient([
            'POST ' . self::MARK_REPLY => new MockResponse('{}', ['http_code' => $replyStatus]),
            'POST ' . self::MARK_MENTION => new MockResponse('{}', ['http_code' => $mentionStatus]),
        ]);
    }

    private function source(RoutedHttpClient $http): LemmyNotificationSource
    {
        return new LemmyNotificationSource(
            SocialApiWiring::lemmyAccountApi($http->client()),
            SocialApiWiring::markdownHtml(),
        );
    }
}
