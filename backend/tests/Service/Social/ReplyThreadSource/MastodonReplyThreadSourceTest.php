<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\ReplyThreadSource;

use App\Service\Comments\Exception\CommentsUnavailableException;
use App\Service\Comments\Model\CommentsAnswerModel;
use App\Service\Sanitize\EntrySanitizer;
use App\Service\Sanitize\TrailingBlankRemover;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\MastodonContextReplies;
use App\Service\Social\PostResolver\MastodonPostResolver;
use App\Service\Social\ReplyThreadSource\MastodonReplyThreadSource;
use App\Tests\Support\AssertsRefusal;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class MastodonReplyThreadSourceTest extends TestCase
{
    use AssertsRefusal;

    private const string STATUS_URL = 'https://mastodon.social/@Gargron/110';
    private const string SEARCH = 'GET https://mastodon.example/api/v2/search?q='
        . 'https%3A%2F%2Fmastodon.social%2F%40Gargron%2F110&resolve=true&type=statuses&limit=1';
    private const string ACCOUNT_CONTEXT = 'GET https://mastodon.example/api/v1/statuses/9/context';
    private const string PUBLIC_CONTEXT = 'GET https://mastodon.social/api/v1/statuses/110/context';
    private const string CONTEXT = '{"ancestors":[],"descendants":[{"id":"200","in_reply_to_id":"110",'
        . '"visibility":"public","url":"https://hachyderm.io/@ann/200","content":"<p>Agreed</p>",'
        . '"created_at":"2026-10-16T09:00:00Z","account":{"acct":"ann@hachyderm.io"}}]}';

    public function testTheAccountResolvesThePostThenAsksItsContext(): void
    {
        $http = $this->answeringTheAccount(new MockResponse(self::CONTEXT));

        $answer = $this->source($http)->asAccount(SocialAccounts::mastodon(), self::STATUS_URL);

        self::assertEquals(CommentsAnswerModel::settled(self::CONTEXT), $answer);
        self::assertSame([self::SEARCH, self::ACCOUNT_CONTEXT], $http->requestLines());
        self::assertContains('Authorization: Bearer ' . SocialAccounts::ACCESS_TOKEN, $http->requests[1]['headers']);
    }

    public function testAnInstanceStillGatheringRepliesSaysSo(): void
    {
        $http = $this->answeringTheAccount(new MockResponse(self::CONTEXT, [
            'response_headers' => ['Mastodon-Async-Refresh: id="ImNvbnRleHQ6MTEwIg", retry=5, result_count=1'],
        ]));

        $answer = $this->source($http)->asAccount(SocialAccounts::mastodon(), self::STATUS_URL);

        self::assertEquals(CommentsAnswerModel::stillGathering(self::CONTEXT), $answer);
    }

    public function testAContextGoneFromTheAccountsInstanceIsNotFound(): void
    {
        $http = $this->answeringTheAccount(self::answer(Response::HTTP_NOT_FOUND));

        $this->expectException(SocialPostNotFoundException::class);

        $this->source($http)->asAccount(SocialAccounts::mastodon(), self::STATUS_URL);
    }

    public function testThePublicContextComesFromTheOrigin(): void
    {
        $http = new RoutedHttpClient([self::PUBLIC_CONTEXT => new MockResponse(self::CONTEXT)]);

        $answer = $this->source($http)->inPublic(self::STATUS_URL);

        self::assertEquals(CommentsAnswerModel::settled(self::CONTEXT), $answer);
        self::assertSame([self::PUBLIC_CONTEXT], $http->requestLines());
        self::assertSame([], preg_grep('/^Authorization:/i', $http->requests[0]['headers']));
    }

    public function testAnAddressOfAnotherShapeIsUnreachableAndAsksNothing(): void
    {
        $http = new RoutedHttpClient([]);

        $this->assertRefused(
            fn (): CommentsAnswerModel => $this->source($http)->inPublic('https://pixelfed.example/p/alice/123'),
            SocialNetworkUnreachableException::class,
            'A Pixelfed address names no Mastodon status.',
        );

        self::assertSame([], $http->requests);
    }

    #[DataProvider('closedToThePublic')]
    public function testAnOriginClosedToThePublicIsUnreachable(int $status): void
    {
        $http = new RoutedHttpClient([self::PUBLIC_CONTEXT => self::answer($status)]);

        try {
            $this->source($http)->inPublic(self::STATUS_URL);
            self::fail('An origin closed to the public must be unreachable.');
        } catch (SocialNetworkUnreachableException $exception) {
            self::assertSame('The Mastodon instance shows its statuses to accounts only.', $exception->getMessage());
        }
    }

    /** @return iterable<string, array{int}> */
    public static function closedToThePublic(): iterable
    {
        yield 'unauthorized' => [Response::HTTP_UNAUTHORIZED];
        yield 'forbidden' => [Response::HTTP_FORBIDDEN];
    }

    public function testAPostGoneFromItsOriginIsNotFound(): void
    {
        $http = new RoutedHttpClient([self::PUBLIC_CONTEXT => self::answer(Response::HTTP_NOT_FOUND)]);

        $this->expectException(SocialPostNotFoundException::class);

        $this->source($http)->inPublic(self::STATUS_URL);
    }

    public function testAThrottlingOriginIsAThrottledNetworkWithItsWait(): void
    {
        $http = new RoutedHttpClient([self::PUBLIC_CONTEXT => new MockResponse('{}', [
            'http_code' => Response::HTTP_TOO_MANY_REQUESTS,
            'response_headers' => ['Retry-After: 30'],
        ])]);

        try {
            $this->source($http)->inPublic(self::STATUS_URL);
            self::fail('A throttling origin must reach the caller as a throttled network.');
        } catch (SocialNetworkThrottledException $exception) {
            self::assertSame(30, $exception->retryAfterSeconds);
        }
    }

    public function testAFailingOriginIsAnUnreachableNetwork(): void
    {
        $http = new RoutedHttpClient([self::PUBLIC_CONTEXT => self::answer(Response::HTTP_INTERNAL_SERVER_ERROR)]);

        $this->assertRefused(
            fn (): CommentsAnswerModel => $this->source($http)->inPublic(self::STATUS_URL),
            SocialNetworkUnreachableException::class,
            'A failing origin must be an unreachable network.',
        );
    }

    public function testTheRepliesAreReadFromTheContext(): void
    {
        $replies = $this->source(new RoutedHttpClient([]))->repliesIn(self::STATUS_URL, self::CONTEXT);

        self::assertCount(1, $replies);
        self::assertSame('https://hachyderm.io/@ann/200', $replies[0]->comment->url);
    }

    public function testAnAnswerWithoutDescendantsHasNoReplies(): void
    {
        $this->expectException(CommentsUnavailableException::class);

        $this->source(new RoutedHttpClient([]))->repliesIn(self::STATUS_URL, '{}');
    }

    private function answeringTheAccount(MockResponse $context): RoutedHttpClient
    {
        return new RoutedHttpClient([
            self::SEARCH => new MockResponse('{"statuses":[{"id":"9"}]}'),
            self::ACCOUNT_CONTEXT => $context,
        ]);
    }

    private function source(RoutedHttpClient $http): MastodonReplyThreadSource
    {
        $accountApi = SocialApiWiring::mastodonAccountApi($http->client());

        return new MastodonReplyThreadSource(
            new MastodonPostResolver($accountApi),
            $accountApi,
            SocialApiWiring::mastodonApiClient($http->client()),
            new MastodonContextReplies(new EntrySanitizer(new TrailingBlankRemover())),
        );
    }

    private static function answer(int $status): MockResponse
    {
        return new MockResponse('{}', ['http_code' => $status]);
    }
}
