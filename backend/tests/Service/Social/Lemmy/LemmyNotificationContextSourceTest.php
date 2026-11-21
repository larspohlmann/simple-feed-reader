<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Lemmy;

use App\Entity\NotificationContext;
use App\Enum\SocialNotificationKind;
use App\Service\Social\Lemmy\LemmyNotificationContextSource;
use App\Service\Social\Notification\Model\ContextReferenceModel;
use App\Service\Social\Notification\Model\FetchedNotificationModel;
use App\Tests\Support\NotificationRows;
use App\Tests\Support\ReadsFixtures;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class LemmyNotificationContextSourceTest extends TestCase
{
    use ReadsFixtures;

    private const string COMMENT = 'https://lemmy.example/api/v3/comment?id=';

    public function testAddsTheAnsweredComment(): void
    {
        $http = new RoutedHttpClient(['GET ' . self::COMMENT . '26906403' => $this->parentComment()]);

        $subjects = $this->source($http)->findSubjects(
            SocialApiWiring::lemmyAccount(),
            [self::row('reply:7', '26906403')],
        );

        self::assertSame(['reply:7'], array_keys($subjects));
        self::assertSame('My original comment', $subjects['reply:7']->context->answeredExcerpt);
        self::assertSame('A post', $subjects['reply:7']->context->threadTitle);
        self::assertSame('c/selfhosted', $subjects['reply:7']->context->threadPlace);
    }

    public function testAQuotedLineInTheAnsweredCommentReadsAsPlainText(): void
    {
        $parent = ['comment_view' => ['comment' => ['content' => "> earlier\n\nMy [answer](https://x.test)"]]];
        $http = new RoutedHttpClient([
            'GET ' . self::COMMENT . '26906403' => new MockResponse((string) json_encode($parent)),
        ]);

        $subjects = $this->source($http)->findSubjects(
            SocialApiWiring::lemmyAccount(),
            [self::row('reply:7', '26906403')],
        );

        self::assertSame('earlier My answer', $subjects['reply:7']->context->answeredExcerpt);
    }

    public function testAGoneParentFindsNoContext(): void
    {
        $http = RoutedHttpClient::answering(
            static fn (): MockResponse => new MockResponse('{"error":"couldnt_find_comment"}', [
                'http_code' => Response::HTTP_NOT_FOUND,
            ]),
        );
        $subjects = $this->source($http)->findSubjects(
            SocialApiWiring::lemmyAccount(),
            [self::row('reply:7', '26906403')],
        );

        self::assertSame([], $subjects);
    }

    public function testARefusedLookupFindsNoContextAndTheNextIsLookedUp(): void
    {
        $http = RoutedHttpClient::answering(
            fn (string $method, string $url): MockResponse => str_ends_with($url, '=1')
                ? new MockResponse('{"error":"banned"}', ['http_code' => Response::HTTP_BAD_REQUEST])
                : $this->parentComment(),
        );
        $subjects = $this->source($http)->findSubjects(
            SocialApiWiring::lemmyAccount(),
            [self::row('reply:1', '1'), self::row('reply:2', '2')],
        );

        self::assertSame(['reply:2'], array_keys($subjects));
        self::assertSame('My original comment', $subjects['reply:2']->context->answeredExcerpt);
    }

    public function testAThrottleStopsTheLookups(): void
    {
        $http = new RoutedHttpClient([
            'GET ' . self::COMMENT . '1' => $this->parentComment(),
            'GET ' . self::COMMENT . '2' => new MockResponse('{}', ['http_code' => Response::HTTP_TOO_MANY_REQUESTS]),
        ]);
        $subjects = $this->source($http)->findSubjects(
            SocialApiWiring::lemmyAccount(),
            [self::row('reply:1', '1'), self::row('reply:2', '2'), self::row('reply:3', '3')],
        );

        self::assertSame(['reply:1'], array_keys($subjects));
        self::assertSame('My original comment', $subjects['reply:1']->context->answeredExcerpt);
        self::assertCount(2, $http->requests);
    }

    public function testAnUnavailableInstanceStopsTheLookups(): void
    {
        $http = RoutedHttpClient::answering(
            fn (string $method, string $url): MockResponse => str_ends_with($url, '=2')
                ? new MockResponse('{}', ['http_code' => Response::HTTP_SERVICE_UNAVAILABLE])
                : $this->parentComment(),
        );
        $subjects = $this->source($http)->findSubjects(
            SocialApiWiring::lemmyAccount(),
            [self::row('reply:1', '1'), self::row('reply:2', '2'), self::row('reply:3', '3')],
        );

        self::assertSame(['reply:1'], array_keys($subjects));
        $unavailable = 'GET ' . self::COMMENT . '2';
        self::assertSame(
            ['GET ' . self::COMMENT . '1', $unavailable, $unavailable, $unavailable],
            $http->requestLines(),
        );
    }

    public function testTwoRowsAnsweringTheSameCommentAskForItOnce(): void
    {
        $http = new RoutedHttpClient(['GET ' . self::COMMENT . '26906403' => $this->parentComment()]);

        $subjects = $this->source($http)->findSubjects(
            SocialApiWiring::lemmyAccount(),
            [self::row('reply:7', '26906403'), self::row('reply:8', '26906403')],
        );

        self::assertCount(1, $http->requests);
        self::assertSame(['reply:7', 'reply:8'], array_keys($subjects));
        self::assertSame('My original comment', $subjects['reply:7']->context->answeredExcerpt);
        self::assertSame('My original comment', $subjects['reply:8']->context->answeredExcerpt);
    }

    public function testRowsWithoutReferencesMakeNoCall(): void
    {
        $http = RoutedHttpClient::answering(static fn (): MockResponse => new MockResponse('{}'));
        $subjects = $this->source($http)->findSubjects(SocialApiWiring::lemmyAccount(), [self::row('reply:6', null)]);

        self::assertSame([], $subjects);
        self::assertSame([], $http->requests);
    }

    private function parentComment(): MockResponse
    {
        return new MockResponse(
            $this->fixture('lemmy/notifications-parent-comment.json'),
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
