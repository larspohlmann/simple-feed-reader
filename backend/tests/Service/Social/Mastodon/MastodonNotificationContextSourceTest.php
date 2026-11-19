<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Mastodon;

use App\Entity\NotificationContext;
use App\Enum\SocialNotificationKind;
use App\Service\Social\Mastodon\MastodonNotificationContextSource;
use App\Service\Social\Notification\Model\ContextReferenceModel;
use App\Service\Social\Notification\Model\FetchedNotificationModel;
use App\Tests\Support\NotificationRows;
use App\Tests\Support\ReadsFixtures;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class MastodonNotificationContextSourceTest extends TestCase
{
    use ReadsFixtures;

    private const string STATUSES = 'https://mastodon.example/api/v1/statuses?';

    public function testAddsTheAnsweredPostsInOneCall(): void
    {
        $http = new RoutedHttpClient(['GET ' . self::STATUSES . 'id[]=4000' => $this->statuses()]);

        $contexts = $this->source($http)->contextsOf(
            SocialApiWiring::mastodonAccount(),
            [self::row('1', '4000'), self::row('2', '4000')],
        );

        self::assertSame([self::STATUSES . 'id[]=4000'], $http->requestedUrls());
        self::assertSame([1, 2], array_keys($contexts));
        self::assertSame('My own post', $contexts['1']->answeredExcerpt);
        self::assertSame('My own post', $contexts['2']->answeredExcerpt);
        self::assertFalse($contexts['1']->onComment);
    }

    public function testAPostMissingFromTheAnswerFindsNoContextForItsRow(): void
    {
        $http = new RoutedHttpClient([
            'GET ' . self::STATUSES . 'id[]=4000&id[]=4002' => $this->statuses(),
        ]);
        $contexts = $this->source($http)->contextsOf(
            SocialApiWiring::mastodonAccount(),
            [self::row('1', '4000'), self::row('2', '4002')],
        );

        self::assertSame([1], array_keys($contexts));
        self::assertSame('My own post', $contexts['1']->answeredExcerpt);
    }

    public function testAServerWithoutBatchStatusesStoresRepliesWithoutContext(): void
    {
        $http = RoutedHttpClient::answering(
            static fn (): MockResponse => new MockResponse('{"error":"Record not found"}', [
                'http_code' => Response::HTTP_NOT_FOUND,
            ]),
        );

        $contexts = $this->source($http)->contextsOf(SocialApiWiring::mastodonAccount(), [self::row('1', '4000')]);

        self::assertSame([], $contexts);
    }

    public function testATokenWithoutTheStatusesScopeStoresRepliesWithoutContext(): void
    {
        $http = RoutedHttpClient::answering(
            static fn (): MockResponse => new MockResponse(
                '{"error":"This action is outside the authorized scopes"}',
                ['http_code' => Response::HTTP_FORBIDDEN],
            ),
        );

        $contexts = $this->source($http)->contextsOf(SocialApiWiring::mastodonAccount(), [self::row('1', '4000')]);

        self::assertSame([], $contexts);
    }

    public function testChunksOfTwenty(): void
    {
        $http = RoutedHttpClient::answering(static fn (): MockResponse => new MockResponse('[]'));
        $rows = [];
        foreach (range(1, 21) as $number) {
            $rows[] = self::row((string) $number, (string) (4000 + $number));
        }

        $this->source($http)->contextsOf(SocialApiWiring::mastodonAccount(), $rows);

        self::assertCount(2, $http->requests);
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function answeredReplies(): iterable
    {
        yield 'a reply of yours with text' => [
            ['id' => '4000', 'content' => '<p>My reply</p>', 'in_reply_to_id' => '3999'],
            'My reply',
        ];
        yield 'a reply of yours without text keeps the excerpt' => [
            ['id' => '4000', 'in_reply_to_id' => '3999'],
            'Kept',
        ];
    }

    /** @param array<string, string> $status */
    #[DataProvider('answeredReplies')]
    public function testAnAnsweredReplyOfYoursIsYourComment(array $status, string $excerpt): void
    {
        $answer = new MockResponse((string) json_encode([$status]));
        $http = new RoutedHttpClient(['GET ' . self::STATUSES . 'id[]=4000' => $answer]);
        $row = NotificationRows::fetched(
            '1',
            SocialNotificationKind::Reply,
            new ContextReferenceModel(answeredId: '4000'),
            new NotificationContext(answeredExcerpt: 'Kept'),
        );

        $contexts = $this->source($http)->contextsOf(SocialApiWiring::mastodonAccount(), [$row]);

        self::assertSame($excerpt, $contexts['1']->answeredExcerpt);
        self::assertTrue($contexts['1']->onComment);
    }

    public function testEveryAnsweredPostOfEveryChunkIsApplied(): void
    {
        $http = RoutedHttpClient::answering(static function (string $method, string $url): MockResponse {
            preg_match_all('/id\[]=(\d+)/', $url, $ids);

            return new MockResponse((string) json_encode(array_map(
                static fn (string $id): array => ['id' => $id, 'content' => 'Post ' . $id],
                $ids[1],
            )));
        });
        $rows = [];
        foreach (range(1, 21) as $number) {
            $rows[] = self::row((string) $number, (string) (4000 + $number));
        }

        $contexts = $this->source($http)->contextsOf(SocialApiWiring::mastodonAccount(), $rows);

        self::assertSame(range(1, 21), array_keys($contexts));
        self::assertSame('Post 4001', $contexts['1']->answeredExcerpt);
        self::assertSame('Post 4002', $contexts['2']->answeredExcerpt);
        self::assertSame('Post 4021', $contexts['21']->answeredExcerpt);
    }

    public function testAFailedChunkLeavesTheOtherChunksApplied(): void
    {
        $http = RoutedHttpClient::answering(
            static fn (string $method, string $url): MockResponse => str_contains($url, '=4001')
                ? new MockResponse('{}', ['http_code' => Response::HTTP_TOO_MANY_REQUESTS])
                : new MockResponse((string) json_encode([['id' => '4021', 'content' => '<p>Later</p>']])),
        );
        $rows = [];
        foreach (range(1, 21) as $number) {
            $rows[] = self::row((string) $number, (string) (4000 + $number));
        }

        $contexts = $this->source($http)->contextsOf(SocialApiWiring::mastodonAccount(), $rows);

        self::assertSame([21], array_keys($contexts));
        self::assertSame('Later', $contexts['21']->answeredExcerpt);
    }

    private function statuses(): MockResponse
    {
        return new MockResponse(
            $this->fixture('Mastodon/notifications-statuses.json'),
        );
    }

    private static function row(string $remoteId, string $answeredId): FetchedNotificationModel
    {
        return NotificationRows::fetched(
            $remoteId,
            SocialNotificationKind::Reply,
            new ContextReferenceModel(answeredId: $answeredId),
        );
    }

    private function source(RoutedHttpClient $http): MastodonNotificationContextSource
    {
        return new MastodonNotificationContextSource(SocialApiWiring::mastodonAccountApi($http->client()));
    }
}
