<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\ReplyThreadSource;

use App\Service\Bluesky\AppViewClient;
use App\Service\Bluesky\PostEmbedRenderer;
use App\Service\Comments\Exception\CommentsUnavailableException;
use App\Service\Comments\Model\CommentsAnswerModel;
use App\Service\Fetch\Exception\FeedThrottledException;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\HostThrottle;
use App\Service\Sanitize\EntrySanitizer;
use App\Service\Sanitize\TrailingBlankRemover;
use App\Service\Social\BlueskyThreadReplies;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\ReplyThreadSource\BlueskyReplyThreadSource;
use App\Tests\Support\AssertsRefusal;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialApiWiring;
use App\Tests\Support\StubFeedFetcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class BlueskyReplyThreadSourceTest extends TestCase
{
    use AssertsRefusal;

    private const string POST_URI = 'at://did:plc:bob/app.bsky.feed.post/3abc';
    private const string THREAD_QUERY = 'app.bsky.feed.getPostThread?uri='
        . 'at%3A%2F%2Fdid%3Aplc%3Abob%2Fapp.bsky.feed.post%2F3abc&depth=6&parentHeight=0';
    private const string SESSION = 'POST https://pds.example.test/xrpc/com.atproto.server.createSession';
    private const string ACCOUNT_THREAD = 'GET https://pds.example.test/xrpc/' . self::THREAD_QUERY;
    private const string PUBLIC_THREAD = 'https://public.api.bsky.app/xrpc/' . self::THREAD_QUERY;
    private const string THREAD = '{"thread":{"$type":"app.bsky.feed.defs#threadViewPost","replies":[{'
        . '"$type":"app.bsky.feed.defs#threadViewPost","replies":[],'
        . '"post":{"uri":"at://did:plc:ann/app.bsky.feed.post/r1","author":{"did":"did:plc:ann"},'
        . '"record":{"text":"Agreed","createdAt":"2026-10-16T09:00:00Z"}}}]}}';

    private StubFeedFetcher $appView;

    protected function setUp(): void
    {
        $this->appView = new StubFeedFetcher();
    }

    public function testTheAccountAsksItsOwnServiceForTheThread(): void
    {
        $http = $this->answeringTheAccount(new MockResponse(self::THREAD));

        $answer = $this->source($http)->asAccount(SocialApiWiring::blueskyAccount(), self::POST_URI);

        self::assertEquals(CommentsAnswerModel::settled(self::THREAD), $answer);
        self::assertSame([self::SESSION, self::ACCOUNT_THREAD], $http->requestLines());
        self::assertContains('Authorization: Bearer jwt-1', $http->requests[1]['headers']);
    }

    public function testAThreadGoneFromTheAccountIsNotFound(): void
    {
        $http = $this->answeringTheAccount(self::json(['error' => 'NotFound'], Response::HTTP_BAD_REQUEST));

        $this->expectException(SocialPostNotFoundException::class);

        $this->source($http)->asAccount(SocialApiWiring::blueskyAccount(), self::POST_URI);
    }

    /** @return iterable<string, array{MockResponse}> */
    public static function failuresThatAreNoMissingPost(): iterable
    {
        yield 'another refusal' => [self::json(['error' => 'InvalidRequest'], Response::HTTP_BAD_REQUEST)];
        yield 'a failing service naming no post' => [self::json(['error' => 'NotFound'], Response::HTTP_BAD_GATEWAY)];
    }

    #[DataProvider('failuresThatAreNoMissingPost')]
    public function testAnyOtherFailureOfTheAccountsServiceIsUnreachable(MockResponse $failure): void
    {
        $http = $this->answeringTheAccount($failure);

        $this->assertRefused(
            fn (): CommentsAnswerModel => $this->source($http)->asAccount(
                SocialApiWiring::blueskyAccount(),
                self::POST_URI,
            ),
            SocialNetworkUnreachableException::class,
            'A failure that names no missing post must be an unreachable network.',
        );
    }

    public function testThePublicThreadComesFromTheAppView(): void
    {
        $this->appView->willReturnBody(self::PUBLIC_THREAD, self::THREAD);

        $answer = $this->source(new RoutedHttpClient([]))->inPublic(self::POST_URI);

        self::assertEquals(CommentsAnswerModel::settled(self::THREAD), $answer);
        self::assertSame([self::PUBLIC_THREAD], $this->appView->fetchedUrls);
    }

    public function testAThrottlingAppViewIsAThrottledNetworkWithItsWait(): void
    {
        $this->appView->willThrow(self::PUBLIC_THREAD, new FeedThrottledException('Slow down.', 120));

        try {
            $this->source(new RoutedHttpClient([]))->inPublic(self::POST_URI);
            self::fail('A throttling AppView must reach the caller as a throttled network.');
        } catch (SocialNetworkThrottledException $exception) {
            self::assertSame(120, $exception->retryAfterSeconds);
        }
    }

    public function testAThrottledAppViewIsNotAskedAgainWhileTheWaitLasts(): void
    {
        $this->appView->willThrow(self::PUBLIC_THREAD, new FeedThrottledException('Slow down.', 120));
        $source = $this->source(new RoutedHttpClient([]));
        $attempt = static fn (): CommentsAnswerModel => $source->inPublic(self::POST_URI);
        $this->assertRefused($attempt, SocialNetworkThrottledException::class, 'The first ask must be throttled.');

        try {
            $attempt();
            self::fail('The wait must still hold.');
        } catch (SocialNetworkThrottledException $exception) {
            self::assertSame(120, $exception->retryAfterSeconds);
        }

        self::assertCount(1, $this->appView->fetchedUrls);
    }

    public function testAnUnreachableAppViewIsAnUnreachableNetwork(): void
    {
        $failure = new FeedUnreachableException('Down.', Response::HTTP_BAD_GATEWAY);
        $this->appView->willThrow(self::PUBLIC_THREAD, $failure);

        try {
            $this->source(new RoutedHttpClient([]))->inPublic(self::POST_URI);
            self::fail('An unreachable AppView must reach the caller.');
        } catch (SocialNetworkUnreachableException $exception) {
            self::assertNotInstanceOf(SocialNetworkThrottledException::class, $exception);
            self::assertSame($failure, $exception->getPrevious());
        }
    }

    public function testAnAppViewAnswerWithoutAThreadIsAnUnreachableNetwork(): void
    {
        $this->appView->willReturnBody(self::PUBLIC_THREAD, '{}');

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->source(new RoutedHttpClient([]))->inPublic(self::POST_URI);
    }

    public function testTheRepliesAreReadFromTheThread(): void
    {
        $replies = $this->source(new RoutedHttpClient([]))->repliesIn(self::POST_URI, self::THREAD);

        self::assertCount(1, $replies);
        self::assertSame('https://bsky.app/profile/did:plc:ann/post/r1', $replies[0]->comment()->url);
    }

    public function testAnAnswerWithoutAThreadHasNoReplies(): void
    {
        $this->expectException(CommentsUnavailableException::class);

        $this->source(new RoutedHttpClient([]))->repliesIn(self::POST_URI, '{}');
    }

    private function answeringTheAccount(MockResponse $thread): RoutedHttpClient
    {
        return new RoutedHttpClient([
            self::SESSION => SocialApiWiring::blueskySessionAnswer('jwt-1'),
            self::ACCOUNT_THREAD => $thread,
        ]);
    }

    private function source(RoutedHttpClient $http): BlueskyReplyThreadSource
    {
        $clock = new MockClock('2026-10-16 10:00:00');

        return new BlueskyReplyThreadSource(
            SocialApiWiring::blueskySessions($http->client()),
            new AppViewClient($this->appView, new HostThrottle(new ArrayAdapter(clock: $clock), $clock)),
            new BlueskyThreadReplies(new PostEmbedRenderer(), new EntrySanitizer(new TrailingBlankRemover())),
            6,
        );
    }

    /** @param array<string, string> $body */
    private static function json(array $body, int $status): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['http_code' => $status]);
    }
}
