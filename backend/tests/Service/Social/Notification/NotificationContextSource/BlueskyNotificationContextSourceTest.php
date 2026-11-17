<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Notification\NotificationContextSource;

use App\Entity\NotificationActor;
use App\Entity\NotificationSubject;
use App\Enum\SocialNotificationKind;
use App\Service\Social\Notification\BlueskyPostLookup;
use App\Service\Social\Notification\Model\ContextReferenceModel;
use App\Service\Social\Notification\Model\FetchedNotificationModel;
use App\Service\Social\Notification\NotificationContextSource\BlueskyNotificationContextSource;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialApiWiring;
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

    public function testAddsTheAnsweredPostAndTheThreadStart(): void
    {
        $http = $this->http(self::posts());

        $rows = $this->source($http)->withContext(
            SocialApiWiring::blueskyAccount(),
            [self::row(SocialNotificationKind::Reply, new ContextReferenceModel(self::MINE, self::ROOT))],
        );

        $context = $rows[0]->subject->context;
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

        $rows = $this->source($http)->withContext(
            SocialApiWiring::blueskyAccount(),
            [self::row(SocialNotificationKind::Reply, new ContextReferenceModel(self::MINE, self::ROOT))],
        );

        self::assertSame('zoe.bsky.social', $rows[0]->subject->context->threadBy);
        self::assertNull($rows[0]->subject->context->answeredExcerpt);
    }

    public function testNoThreadLineForYourOwnThreadOrADirectReplyToItsStart(): void
    {
        $http = $this->http(self::posts());

        $rows = $this->source($http)->withContext(SocialApiWiring::blueskyAccount(), [
            self::row(SocialNotificationKind::Reply, new ContextReferenceModel(self::MINE, self::OWN_ROOT)),
            self::row(SocialNotificationKind::Reply, new ContextReferenceModel(self::MINE, self::MINE)),
        ]);

        foreach ($rows as $row) {
            self::assertSame('My own post', $row->subject->context->answeredExcerpt);
            self::assertNull($row->subject->context->threadTitle);
            self::assertNull($row->subject->context->threadBy);
        }
        self::assertSame([self::lookup(self::MINE)], $this->lookups($http));
    }

    public function testAReplyToSomeoneElsesPostQuotesNothing(): void
    {
        $http = $this->http((string) json_encode(['posts' => [[
            'uri' => self::THEIRS,
            'author' => ['did' => 'did:plc:someone-else', 'handle' => 'else.bsky.social', 'displayName' => 'Else'],
            'record' => ['text' => 'Not your post'],
        ], [
            'uri' => self::ROOT,
            'author' => ['did' => 'did:plc:zoe', 'handle' => 'zoe.bsky.social', 'displayName' => 'Zoe'],
            'record' => ['text' => 'How do you all handle feeds?'],
        ]]]));

        $rows = $this->source($http)->withContext(
            SocialApiWiring::blueskyAccount(),
            [self::row(SocialNotificationKind::Reply, new ContextReferenceModel(self::THEIRS, self::ROOT))],
        );

        self::assertNull($rows[0]->subject->context->answeredExcerpt);
        self::assertSame('Zoe', $rows[0]->subject->context->threadBy);
        self::assertSame([self::lookup(self::ROOT)], $this->lookups($http));
    }

    public function testAQuoteGetsYourQuotedPost(): void
    {
        $rows = $this->source($this->http(self::posts()))->withContext(
            SocialApiWiring::blueskyAccount(),
            [self::row(SocialNotificationKind::Quote, new ContextReferenceModel(self::QUOTED))],
        );

        self::assertSame('The post you quoted', $rows[0]->subject->context->answeredExcerpt);
    }

    public function testAnUnreachableAppViewLeavesTheRowsAlone(): void
    {
        $http = RoutedHttpClient::answering(static fn (string $method, string $url): MockResponse => match (true) {
            str_contains($url, 'createSession') => SocialApiWiring::blueskySessionAnswer('jwt'),
            default => new MockResponse('{}', ['http_code' => Response::HTTP_BAD_GATEWAY]),
        });
        $row = self::row(SocialNotificationKind::Reply, new ContextReferenceModel(self::MINE, self::ROOT));

        $rows = $this->source($http)->withContext(SocialApiWiring::blueskyAccount(), [$row]);

        self::assertEquals([$row], $rows);
    }

    public function testAThrottledAppViewLeavesTheRowsAlone(): void
    {
        $http = RoutedHttpClient::answering(static fn (string $method, string $url): MockResponse => match (true) {
            str_contains($url, 'createSession') => SocialApiWiring::blueskySessionAnswer('jwt'),
            default => new MockResponse('{}', ['http_code' => Response::HTTP_TOO_MANY_REQUESTS]),
        });
        $row = self::row(SocialNotificationKind::Quote, new ContextReferenceModel(self::QUOTED));

        $rows = $this->source($http)->withContext(SocialApiWiring::blueskyAccount(), [$row]);

        self::assertEquals([$row], $rows);
    }

    private static function posts(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../../Fixtures/Bluesky/notifications-context-posts.json');
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
    ): FetchedNotificationModel {
        return new FetchedNotificationModel(
            'at://did:plc:frank/app.bsky.feed.post/3l05',
            $kind,
            new NotificationActor('frank.test', null, null, 'https://bsky.app/profile/did:plc:frank'),
            new NotificationSubject('at://did:plc:frank/app.bsky.feed.post/3l05', 'a reply', 'https://bsky.app'),
            new \DateTimeImmutable('2026-11-16 09:00:00', new \DateTimeZone('UTC')),
            false,
            $references,
        );
    }

    private function source(RoutedHttpClient $http): BlueskyNotificationContextSource
    {
        return new BlueskyNotificationContextSource(
            new BlueskyPostLookup(SocialApiWiring::blueskySessions($http->client())),
        );
    }
}
