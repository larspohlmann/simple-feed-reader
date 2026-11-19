<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Bluesky;

use App\Enum\SocialNotificationKind;
use App\Service\Social\Bluesky\BlueskyPostLookup;
use App\Service\Social\Notification\Model\ContextReferenceModel;
use App\Service\Social\Notification\Model\FetchedNotificationModel;
use App\Service\Social\Bluesky\BlueskyNotificationContextSource;
use App\Tests\Support\NotificationRows;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class BlueskyNotificationContextSourceTest extends TestCase
{
    private const string MINE = 'at://did:plc:alice/app.bsky.feed.post/3lmine';
    private const string ROOT = 'at://did:plc:zoe/app.bsky.feed.post/3lroot';
    private const string OWN_ROOT = 'at://did:plc:alice/app.bsky.feed.post/3lownroot';
    private const string QUOTED = 'at://did:plc:alice/app.bsky.feed.post/3lquoted';
    private const string THEIRS = 'at://did:plc:someone-else/app.bsky.feed.post/3ltheirs';
    private const string NOTIFICATION = 'at://did:plc:frank/app.bsky.feed.post/3l05';
    private const string SECOND_NOTIFICATION = 'at://did:plc:frank/app.bsky.feed.post/3l06';

    public function testAddsTheAnsweredPostAndTheThreadStart(): void
    {
        $http = $this->http(self::posts());

        $contexts = $this->source($http)->contextsOf(
            SocialApiWiring::blueskyAccount(),
            [self::row(SocialNotificationKind::Reply, new ContextReferenceModel(self::MINE, self::ROOT))],
        );

        $context = $contexts[self::NOTIFICATION];
        self::assertSame('My own post', $context->answeredExcerpt);
        self::assertSame('How do you all handle feeds?', $context->threadTitle);
        self::assertSame('Zoe', $context->threadBy);
        self::assertSame([self::lookup(self::MINE, self::ROOT)], $this->lookups($http));
    }

    public function testThreadByFallsBackToTheHandle(): void
    {
        $http = $this->http((string) json_encode(['posts' => [[
            'uri' => self::ROOT,
            'author' => ['did' => 'did:plc:zoe', 'handle' => 'zoe.bsky.social', 'displayName' => ''],
            'record' => ['text' => 'How do you all handle feeds?'],
        ]]]));

        $contexts = $this->source($http)->contextsOf(
            SocialApiWiring::blueskyAccount(),
            [self::row(SocialNotificationKind::Reply, new ContextReferenceModel(self::MINE, self::ROOT))],
        );

        self::assertSame('zoe.bsky.social', $contexts[self::NOTIFICATION]->threadBy);
        self::assertNull($contexts[self::NOTIFICATION]->answeredExcerpt);
    }

    public function testNoThreadLineForYourOwnThreadOrADirectReplyToItsStart(): void
    {
        $http = $this->http(self::posts());

        $contexts = $this->source($http)->contextsOf(SocialApiWiring::blueskyAccount(), [
            self::row(SocialNotificationKind::Reply, new ContextReferenceModel(self::MINE, self::OWN_ROOT)),
            self::row(
                SocialNotificationKind::Reply,
                new ContextReferenceModel(self::MINE, self::MINE),
                self::SECOND_NOTIFICATION,
            ),
        ]);

        self::assertSame([self::NOTIFICATION, self::SECOND_NOTIFICATION], array_keys($contexts));
        foreach ($contexts as $context) {
            self::assertSame('My own post', $context->answeredExcerpt);
            self::assertNull($context->threadTitle);
            self::assertNull($context->threadBy);
        }
        self::assertSame([self::lookup(self::MINE)], $this->lookups($http));
    }

    public function testAReplyToSomeoneElsesPostQuotesNothing(): void
    {
        $http = $this->http((string) json_encode(['posts' => [[
            'uri' => self::THEIRS,
            'author' => ['did' => 'did:plc:someone-else', 'handle' => 'else.bsky.social', 'displayName' => 'Else'],
            'record' => ['text' => 'Not your post', 'reply' => ['parent' => ['uri' => self::ROOT]]],
        ], [
            'uri' => self::ROOT,
            'author' => ['did' => 'did:plc:zoe', 'handle' => 'zoe.bsky.social', 'displayName' => 'Zoe'],
            'record' => ['text' => 'How do you all handle feeds?'],
        ]]]));

        $contexts = $this->source($http)->contextsOf(
            SocialApiWiring::blueskyAccount(),
            [self::row(SocialNotificationKind::Reply, new ContextReferenceModel(self::THEIRS, self::ROOT))],
        );

        self::assertNull($contexts[self::NOTIFICATION]->answeredExcerpt);
        self::assertFalse($contexts[self::NOTIFICATION]->onComment);
        self::assertSame('Zoe', $contexts[self::NOTIFICATION]->threadBy);
        self::assertSame([self::lookup(self::ROOT)], $this->lookups($http));
    }

    public function testARowWithNeitherPostFoundGetsNoContext(): void
    {
        $contexts = $this->source($this->http(self::posts()))->contextsOf(SocialApiWiring::blueskyAccount(), [
            self::row(SocialNotificationKind::Reply, new ContextReferenceModel(self::MINE)),
            self::row(
                SocialNotificationKind::Reply,
                new ContextReferenceModel(self::THEIRS, self::OWN_ROOT),
                self::SECOND_NOTIFICATION,
            ),
        ]);

        self::assertSame([self::NOTIFICATION], array_keys($contexts));
    }

    public function testTwoRowsAnsweringOnePostAskForItOnce(): void
    {
        $http = $this->http(self::posts());

        $contexts = $this->source($http)->contextsOf(SocialApiWiring::blueskyAccount(), [
            self::row(SocialNotificationKind::Reply, new ContextReferenceModel(self::MINE)),
            self::row(SocialNotificationKind::Reply, new ContextReferenceModel(self::MINE), self::SECOND_NOTIFICATION),
        ]);

        self::assertSame([self::NOTIFICATION, self::SECOND_NOTIFICATION], array_keys($contexts));
        self::assertSame('My own post', $contexts[self::SECOND_NOTIFICATION]->answeredExcerpt);
        self::assertSame([self::lookup(self::MINE)], $this->lookups($http));
    }

    public function testAQuoteGetsYourQuotedPost(): void
    {
        $contexts = $this->source($this->http(self::posts()))->contextsOf(
            SocialApiWiring::blueskyAccount(),
            [self::row(SocialNotificationKind::Quote, new ContextReferenceModel(self::QUOTED))],
        );

        self::assertSame('The post you quoted', $contexts[self::NOTIFICATION]->answeredExcerpt);
    }

    /** @return iterable<string, array{SocialNotificationKind, ContextReferenceModel, array<string, mixed>, bool}> */
    public static function answeredPostsOfYours(): iterable
    {
        $reply = [
            'text' => 'My own post',
            'reply' => ['parent' => ['uri' => self::ROOT], 'root' => ['uri' => self::ROOT]],
        ];
        $topLevel = ['text' => 'My own post'];

        yield 'a reply to your reply in someone else\'s thread' => [
            SocialNotificationKind::Reply,
            new ContextReferenceModel(self::MINE, self::ROOT),
            $reply,
            true,
        ];
        yield 'a reply to your thread-starting post' => [
            SocialNotificationKind::Reply,
            new ContextReferenceModel(self::MINE),
            $topLevel,
            false,
        ];
        yield 'a quote of your reply' => [
            SocialNotificationKind::Quote,
            new ContextReferenceModel(self::MINE),
            $reply,
            true,
        ];
        yield 'a quote of your top-level post' => [
            SocialNotificationKind::Quote,
            new ContextReferenceModel(self::MINE),
            $topLevel,
            false,
        ];
    }

    /** @param array<string, mixed> $answeredRecord */
    #[DataProvider('answeredPostsOfYours')]
    public function testAnAnsweredPostOfYoursIsYourCommentWhenItIsAReply(
        SocialNotificationKind $kind,
        ContextReferenceModel $references,
        array $answeredRecord,
        bool $onComment,
    ): void {
        $posts = (string) json_encode(['posts' => [[
            'uri' => self::MINE,
            'author' => ['did' => 'did:plc:alice', 'handle' => 'alice.bsky.social'],
            'record' => $answeredRecord,
        ]]]);

        $contexts = $this->source($this->http($posts))->contextsOf(
            SocialApiWiring::blueskyAccount(),
            [self::row($kind, $references)],
        );

        self::assertSame($onComment, $contexts[self::NOTIFICATION]->onComment);
        self::assertSame('My own post', $contexts[self::NOTIFICATION]->answeredExcerpt);
    }

    public function testAnUnreachableAppViewFindsNoContext(): void
    {
        $http = RoutedHttpClient::answering(static fn (string $method, string $url): MockResponse => match (true) {
            str_contains($url, 'createSession') => SocialApiWiring::blueskySessionAnswer('jwt'),
            default => new MockResponse('{}', ['http_code' => Response::HTTP_BAD_GATEWAY]),
        });
        $row = self::row(SocialNotificationKind::Reply, new ContextReferenceModel(self::MINE, self::ROOT));

        $contexts = $this->source($http)->contextsOf(SocialApiWiring::blueskyAccount(), [$row]);

        self::assertSame([], $contexts);
    }

    public function testAThrottledAppViewFindsNoContext(): void
    {
        $http = RoutedHttpClient::answering(static fn (string $method, string $url): MockResponse => match (true) {
            str_contains($url, 'createSession') => SocialApiWiring::blueskySessionAnswer('jwt'),
            default => new MockResponse('{}', ['http_code' => Response::HTTP_TOO_MANY_REQUESTS]),
        });
        $row = self::row(SocialNotificationKind::Quote, new ContextReferenceModel(self::QUOTED));

        $contexts = $this->source($http)->contextsOf(SocialApiWiring::blueskyAccount(), [$row]);

        self::assertSame([], $contexts);
    }

    private static function posts(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../Fixtures/Bluesky/notifications-context-posts.json');
    }

    private static function lookup(string ...$uris): string
    {
        return 'https://pds.example.test/xrpc/app.bsky.feed.getPosts?'
            . implode('&', array_map(static fn (string $uri): string => 'uris=' . rawurlencode($uri), $uris));
    }

    /** @return list<string> */
    private function lookups(RoutedHttpClient $http): array
    {
        return array_values(array_filter(
            $http->requestedUrls(),
            static fn (string $url): bool => str_contains($url, 'getPosts'),
        ));
    }

    private function http(string $postsBody): RoutedHttpClient
    {
        return RoutedHttpClient::answering(static fn (string $method, string $url): MockResponse => match (true) {
            str_contains($url, 'createSession') => SocialApiWiring::blueskySessionAnswer('jwt'),
            default => new MockResponse($postsBody),
        });
    }

    private static function row(
        SocialNotificationKind $kind,
        ContextReferenceModel $references,
        string $remoteId = self::NOTIFICATION,
    ): FetchedNotificationModel {
        return NotificationRows::fetched($remoteId, $kind, $references);
    }

    private function source(RoutedHttpClient $http): BlueskyNotificationContextSource
    {
        return new BlueskyNotificationContextSource(
            new BlueskyPostLookup(SocialApiWiring::blueskySessions($http->client())),
        );
    }
}
