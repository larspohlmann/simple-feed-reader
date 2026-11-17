<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Notification\NotificationContextSource;

use App\Entity\NotificationContext;
use App\Enum\SocialNotificationKind;
use App\Service\Social\Notification\Model\ContextReferenceModel;
use App\Service\Social\Notification\Model\FetchedNotificationModel;
use App\Service\Social\Notification\NotificationContextSource\LemmyNotificationContextSource;
use App\Tests\Support\NotificationRows;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class LemmyNotificationContextSourceTest extends TestCase
{
    private const string COMMENT = 'https://lemmy.example/api/v3/comment?id=';

    public function testAddsTheAnsweredComment(): void
    {
        $http = new RoutedHttpClient(['GET ' . self::COMMENT . '26906403' => self::parentComment()]);

        $rows = $this->source($http)->withContext(
            SocialApiWiring::lemmyAccount(),
            [self::row('reply:7', '26906403')],
        );

        self::assertCount(1, $rows);
        self::assertSame('My original comment', $rows[0]->subject->context->answeredExcerpt);
        self::assertSame('A post', $rows[0]->subject->context->threadTitle);
        self::assertSame('c/selfhosted', $rows[0]->subject->context->threadPlace);
    }

    public function testAQuotedLineInTheAnsweredCommentReadsAsPlainText(): void
    {
        $parent = ['comment_view' => ['comment' => ['content' => "> earlier\n\nMy [answer](https://x.test)"]]];
        $http = new RoutedHttpClient([
            'GET ' . self::COMMENT . '26906403' => new MockResponse((string) json_encode($parent)),
        ]);

        $rows = $this->source($http)->withContext(
            SocialApiWiring::lemmyAccount(),
            [self::row('reply:7', '26906403')],
        );

        self::assertSame('earlier My answer', $rows[0]->subject->context->answeredExcerpt);
    }

    public function testAGoneParentLeavesTheRowWithoutAnsweredText(): void
    {
        $http = RoutedHttpClient::answering(
            static fn (): MockResponse => new MockResponse('{"error":"couldnt_find_comment"}', [
                'http_code' => Response::HTTP_NOT_FOUND,
            ]),
        );
        $row = self::row('reply:7', '26906403');

        $rows = $this->source($http)->withContext(SocialApiWiring::lemmyAccount(), [$row]);

        self::assertEquals([$row], $rows);
    }

    public function testARefusedLookupLeavesTheRowAndTheNextIsLookedUp(): void
    {
        $http = RoutedHttpClient::answering(
            static fn (string $method, string $url): MockResponse => str_ends_with($url, '=1')
                ? new MockResponse('{"error":"banned"}', ['http_code' => Response::HTTP_BAD_REQUEST])
                : self::parentComment(),
        );
        $first = self::row('reply:1', '1');

        $rows = $this->source($http)->withContext(
            SocialApiWiring::lemmyAccount(),
            [$first, self::row('reply:2', '2')],
        );

        self::assertEquals($first, $rows[0]);
        self::assertSame('My original comment', $rows[1]->subject->context->answeredExcerpt);
    }

    public function testAThrottleStopsTheLookupsAndKeepsTheRows(): void
    {
        $http = new RoutedHttpClient([
            'GET ' . self::COMMENT . '1' => self::parentComment(),
            'GET ' . self::COMMENT . '2' => new MockResponse('{}', ['http_code' => Response::HTTP_TOO_MANY_REQUESTS]),
        ]);
        $second = self::row('reply:2', '2');
        $third = self::row('reply:3', '3');

        $rows = $this->source($http)->withContext(
            SocialApiWiring::lemmyAccount(),
            [self::row('reply:1', '1'), $second, $third],
        );

        self::assertCount(3, $rows);
        self::assertSame('My original comment', $rows[0]->subject->context->answeredExcerpt);
        self::assertEquals($second, $rows[1]);
        self::assertEquals($third, $rows[2]);
        self::assertCount(2, $http->requests);
    }

    public function testTwoRowsAnsweringTheSameCommentAskForItOnce(): void
    {
        $http = new RoutedHttpClient(['GET ' . self::COMMENT . '26906403' => self::parentComment()]);

        $rows = $this->source($http)->withContext(
            SocialApiWiring::lemmyAccount(),
            [self::row('reply:7', '26906403'), self::row('reply:8', '26906403')],
        );

        self::assertCount(1, $http->requests);
        self::assertSame('My original comment', $rows[0]->subject->context->answeredExcerpt);
        self::assertSame('My original comment', $rows[1]->subject->context->answeredExcerpt);
    }

    public function testRowsWithoutReferencesMakeNoCall(): void
    {
        $http = RoutedHttpClient::answering(static fn (): MockResponse => new MockResponse('{}'));
        $row = self::row('reply:6', null);

        $rows = $this->source($http)->withContext(SocialApiWiring::lemmyAccount(), [$row]);

        self::assertEquals([$row], $rows);
        self::assertSame([], $http->requests);
    }

    private static function parentComment(): MockResponse
    {
        return new MockResponse(
            (string) file_get_contents(__DIR__ . '/../../../../Fixtures/lemmy/notifications-parent-comment.json'),
        );
    }

    private static function row(string $remoteId, ?string $answeredId): FetchedNotificationModel
    {
        return NotificationRows::fetched(
            $remoteId,
            SocialNotificationKind::CommentReply,
            new ContextReferenceModel(answeredId: $answeredId),
            new NotificationContext(threadTitle: 'A post', threadPlace: 'c/selfhosted'),
        );
    }

    private function source(RoutedHttpClient $http): LemmyNotificationContextSource
    {
        return new LemmyNotificationContextSource(
            SocialApiWiring::lemmyAccountApi($http->client()),
            SocialApiWiring::markdownHtml(),
        );
    }
}
