<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Notification\NotificationContextSource;

use App\Entity\NotificationContext;
use App\Enum\SocialNotificationKind;
use App\Service\Social\Notification\Model\ContextReferenceModel;
use App\Service\Social\Notification\Model\FetchedNotificationModel;
use App\Service\Social\Notification\NotificationContextSource\MastodonNotificationContextSource;
use App\Tests\Support\NotificationRows;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class MastodonNotificationContextSourceTest extends TestCase
{
    private const string STATUSES = 'https://mastodon.example/api/v1/statuses?';

    public function testAddsTheAnsweredPostsInOneCall(): void
    {
        $http = new RoutedHttpClient(['GET ' . self::STATUSES . 'id[]=4000' => self::statuses()]);

        $rows = $this->source($http)->withContext(
            SocialApiWiring::mastodonAccount(),
            [self::row('1', '4000'), self::row('2', '4000')],
        );

        self::assertSame([self::STATUSES . 'id[]=4000'], $http->requestedUrls());
        self::assertSame('My own post', $rows[0]->subject->context->answeredExcerpt);
        self::assertSame('My own post', $rows[1]->subject->context->answeredExcerpt);
        self::assertFalse($rows[0]->subject->context->onComment);
    }

    public function testAPostMissingFromTheAnswerLeavesItsRowAlone(): void
    {
        $http = new RoutedHttpClient([
            'GET ' . self::STATUSES . 'id[]=4000&id[]=4002' => self::statuses(),
        ]);
        $missing = self::row('2', '4002');

        $rows = $this->source($http)->withContext(
            SocialApiWiring::mastodonAccount(),
            [self::row('1', '4000'), $missing],
        );

        self::assertSame('My own post', $rows[0]->subject->context->answeredExcerpt);
        self::assertEquals($missing, $rows[1]);
    }

    public function testAServerWithoutBatchStatusesStoresRepliesWithoutContext(): void
    {
        $http = RoutedHttpClient::answering(
            static fn (): MockResponse => new MockResponse('{"error":"Record not found"}', [
                'http_code' => Response::HTTP_NOT_FOUND,
            ]),
        );
        $row = self::row('1', '4000');

        $rows = $this->source($http)->withContext(SocialApiWiring::mastodonAccount(), [$row]);

        self::assertEquals([$row], $rows);
    }

    public function testATokenWithoutTheStatusesScopeStoresRepliesWithoutContext(): void
    {
        $http = RoutedHttpClient::answering(
            static fn (): MockResponse => new MockResponse(
                '{"error":"This action is outside the authorized scopes"}',
                ['http_code' => Response::HTTP_FORBIDDEN],
            ),
        );
        $row = self::row('1', '4000');

        $rows = $this->source($http)->withContext(SocialApiWiring::mastodonAccount(), [$row]);

        self::assertEquals([$row], $rows);
    }

    public function testChunksOfTwenty(): void
    {
        $http = RoutedHttpClient::answering(static fn (): MockResponse => new MockResponse('[]'));
        $rows = [];
        foreach (range(1, 21) as $number) {
            $rows[] = self::row((string) $number, (string) (4000 + $number));
        }

        $this->source($http)->withContext(SocialApiWiring::mastodonAccount(), $rows);

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

        $rows = $this->source($http)->withContext(SocialApiWiring::mastodonAccount(), [$row]);

        self::assertSame($excerpt, $rows[0]->subject->context->answeredExcerpt);
        self::assertTrue($rows[0]->subject->context->onComment);
    }

    public function testEveryAnsweredPostOfEveryChunkIsApplied(): void
    {
        $http = RoutedHttpClient::answering(static function (string $method, string $url): MockResponse {
            preg_match_all('/id\[\]=(\d+)/', $url, $ids);

            return new MockResponse((string) json_encode(array_map(
                static fn (string $id): array => ['id' => $id, 'content' => 'Post ' . $id],
                $ids[1],
            )));
        });
        $rows = [];
        foreach (range(1, 21) as $number) {
            $rows[] = self::row((string) $number, (string) (4000 + $number));
        }

        $result = $this->source($http)->withContext(SocialApiWiring::mastodonAccount(), $rows);

        self::assertSame('Post 4001', $result[0]->subject->context->answeredExcerpt);
        self::assertSame('Post 4002', $result[1]->subject->context->answeredExcerpt);
        self::assertSame('Post 4021', $result[20]->subject->context->answeredExcerpt);
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

        $result = $this->source($http)->withContext(SocialApiWiring::mastodonAccount(), $rows);

        self::assertNull($result[0]->subject->context->answeredExcerpt);
        self::assertSame('Later', $result[20]->subject->context->answeredExcerpt);
    }

    private static function statuses(): MockResponse
    {
        return new MockResponse(
            (string) file_get_contents(__DIR__ . '/../../../../Fixtures/Mastodon/notifications-statuses.json'),
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
