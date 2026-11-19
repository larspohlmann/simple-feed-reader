<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Lemmy;

use App\Entity\NotificationContext;
use App\Enum\SocialNotificationKind;
use App\Service\Social\Notification\Model\ContextReferenceModel;
use App\Service\Social\Notification\Model\FetchedNotificationModel;
use App\Service\Social\Lemmy\LemmyNotificationContextSource;
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

        $contexts = $this->source($http)->contextsOf(
            SocialApiWiring::lemmyAccount(),
            [self::row('reply:7', '26906403')],
        );

        self::assertSame(['reply:7'], array_keys($contexts));
        self::assertSame('My original comment', $contexts['reply:7']->answeredExcerpt);
        self::assertSame('A post', $contexts['reply:7']->threadTitle);
        self::assertSame('c/selfhosted', $contexts['reply:7']->threadPlace);
    }

    public function testAQuotedLineInTheAnsweredCommentReadsAsPlainText(): void
    {
        $parent = ['comment_view' => ['comment' => ['content' => "> earlier\n\nMy [answer](https://x.test)"]]];
        $http = new RoutedHttpClient([
            'GET ' . self::COMMENT . '26906403' => new MockResponse((string) json_encode($parent)),
        ]);

        $contexts = $this->source($http)->contextsOf(
            SocialApiWiring::lemmyAccount(),
            [self::row('reply:7', '26906403')],
        );

        self::assertSame('earlier My answer', $contexts['reply:7']->answeredExcerpt);
    }

    public function testAGoneParentFindsNoContext(): void
    {
        $http = RoutedHttpClient::answering(
            static fn (): MockResponse => new MockResponse('{"error":"couldnt_find_comment"}', [
                'http_code' => Response::HTTP_NOT_FOUND,
            ]),
        );
        $contexts = $this->source($http)->contextsOf(
            SocialApiWiring::lemmyAccount(),
            [self::row('reply:7', '26906403')],
        );

        self::assertSame([], $contexts);
    }

    public function testARefusedLookupFindsNoContextAndTheNextIsLookedUp(): void
    {
        $http = RoutedHttpClient::answering(
            static fn (string $method, string $url): MockResponse => str_ends_with($url, '=1')
                ? new MockResponse('{"error":"banned"}', ['http_code' => Response::HTTP_BAD_REQUEST])
                : self::parentComment(),
        );
        $contexts = $this->source($http)->contextsOf(
            SocialApiWiring::lemmyAccount(),
            [self::row('reply:1', '1'), self::row('reply:2', '2')],
        );

        self::assertSame(['reply:2'], array_keys($contexts));
        self::assertSame('My original comment', $contexts['reply:2']->answeredExcerpt);
    }

    public function testAThrottleStopsTheLookups(): void
    {
        $http = new RoutedHttpClient([
            'GET ' . self::COMMENT . '1' => self::parentComment(),
            'GET ' . self::COMMENT . '2' => new MockResponse('{}', ['http_code' => Response::HTTP_TOO_MANY_REQUESTS]),
        ]);
        $contexts = $this->source($http)->contextsOf(
            SocialApiWiring::lemmyAccount(),
            [self::row('reply:1', '1'), self::row('reply:2', '2'), self::row('reply:3', '3')],
        );

        self::assertSame(['reply:1'], array_keys($contexts));
        self::assertSame('My original comment', $contexts['reply:1']->answeredExcerpt);
        self::assertCount(2, $http->requests);
    }

    public function testAnUnavailableInstanceStopsTheLookups(): void
    {
        $http = RoutedHttpClient::answering(
            static fn (string $method, string $url): MockResponse => str_ends_with($url, '=2')
                ? new MockResponse('{}', ['http_code' => Response::HTTP_SERVICE_UNAVAILABLE])
                : self::parentComment(),
        );
        $contexts = $this->source($http)->contextsOf(
            SocialApiWiring::lemmyAccount(),
            [self::row('reply:1', '1'), self::row('reply:2', '2'), self::row('reply:3', '3')],
        );

        self::assertSame(['reply:1'], array_keys($contexts));
        $unavailable = 'GET ' . self::COMMENT . '2';
        self::assertSame(
            ['GET ' . self::COMMENT . '1', $unavailable, $unavailable, $unavailable],
            $http->requestLines(),
        );
    }

    public function testTwoRowsAnsweringTheSameCommentAskForItOnce(): void
    {
        $http = new RoutedHttpClient(['GET ' . self::COMMENT . '26906403' => self::parentComment()]);

        $contexts = $this->source($http)->contextsOf(
            SocialApiWiring::lemmyAccount(),
            [self::row('reply:7', '26906403'), self::row('reply:8', '26906403')],
        );

        self::assertCount(1, $http->requests);
        self::assertSame(['reply:7', 'reply:8'], array_keys($contexts));
        self::assertSame('My original comment', $contexts['reply:7']->answeredExcerpt);
        self::assertSame('My original comment', $contexts['reply:8']->answeredExcerpt);
    }

    public function testRowsWithoutReferencesMakeNoCall(): void
    {
        $http = RoutedHttpClient::answering(static fn (): MockResponse => new MockResponse('{}'));
        $contexts = $this->source($http)->contextsOf(SocialApiWiring::lemmyAccount(), [self::row('reply:6', null)]);

        self::assertSame([], $contexts);
        self::assertSame([], $http->requests);
    }

    private static function parentComment(): MockResponse
    {
        return new MockResponse(
            (string) file_get_contents(__DIR__ . '/../../../Fixtures/lemmy/notifications-parent-comment.json'),
        );
    }

    private static function row(string $remoteId, ?string $answeredId): FetchedNotificationModel
    {
        return NotificationRows::fetched(
            $remoteId,
            SocialNotificationKind::Reply,
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
