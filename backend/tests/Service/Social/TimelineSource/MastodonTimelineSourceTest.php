<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\TimelineSource;

use App\Entity\Feed;
use App\Entity\SocialFeed;
use App\Enum\SocialFeedKind;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Model\ApiResponseModel;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\TimelinePageModel;
use App\Service\Social\TimelineSource\MastodonTimelineSource;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class MastodonTimelineSourceTest extends TestCase
{
    private const string HOME = 'GET https://mastodon.example/api/v1/timelines/home?limit=40';

    /** @return iterable<string, array{string}> */
    public static function foreignListIds(): iterable
    {
        yield 'parent path' => ['..'];
        yield 'empty' => [''];
        yield 'letters' => ['abc'];
        yield 'too long' => [str_repeat('1', 33)];
    }

    public function testARequestCarriesTheTokenToTheAccountsInstance(): void
    {
        $request = $this->source(new RoutedHttpClient([]))->request(self::socialFeed(SocialFeedKind::Home, ''));

        self::assertSame(self::HOME, $request->method . ' ' . $request->url);
        self::assertSame('Bearer ' . SocialAccounts::ACCESS_TOKEN, $request->headers['Authorization'] ?? null);
    }

    public function testAListRequestAsksOnlyForWhatCameAfterTheNewestSeenStatus(): void
    {
        $list = self::socialFeed(SocialFeedKind::UserList, '42');
        $list->recordNewestSeen('100');

        $request = $this->source(new RoutedHttpClient([]))->request($list);

        self::assertSame('https://mastodon.example/api/v1/timelines/list/42?limit=40&min_id=100', $request->url);
    }

    #[DataProvider('foreignListIds')]
    public function testAForeignListIdIsNeverTurnedIntoARequest(string $listId): void
    {
        $this->expectException(SocialNetworkUnreachableException::class);

        $this->source(new RoutedHttpClient([]))->request(self::socialFeed(SocialFeedKind::UserList, $listId));
    }

    public function testATagRequestReadsTheTagTimelineAfterTheNewestSeenStatus(): void
    {
        $tag = self::socialFeed(SocialFeedKind::Tag, 'caturday');
        $tag->recordNewestSeen('100');

        $request = $this->source(new RoutedHttpClient([]))->request($tag);

        self::assertSame('https://mastodon.example/api/v1/timelines/tag/caturday?limit=40&min_id=100', $request->url);
        self::assertSame('Bearer ' . SocialAccounts::ACCESS_TOKEN, $request->headers['Authorization'] ?? null);
    }

    public function testTrendingAsksForTheCurrentRankingAndNeverForWhatCameAfterTheNewestSeen(): void
    {
        $trending = self::socialFeed(SocialFeedKind::Trending, '');
        $trending->recordNewestSeen('100');

        $request = $this->source(new RoutedHttpClient([]))->request($trending);

        self::assertSame('https://mastodon.example/api/v1/trends/statuses?limit=40', $request->url);
    }

    public function testANonLatinTagIsPercentEncodedInThePath(): void
    {
        $request = $this->source(new RoutedHttpClient([]))->request(self::socialFeed(SocialFeedKind::Tag, '日本語'));

        self::assertSame(
            'https://mastodon.example/api/v1/timelines/tag/' . rawurlencode('日本語') . '?limit=40',
            $request->url,
        );
    }

    /** @return iterable<string, array{string}> */
    public static function foreignTagNames(): iterable
    {
        yield 'parent path' => ['..'];
        yield 'empty' => [''];
        yield 'a query' => ['cat?limit=1'];
        yield 'a slash' => ['cat/day'];
    }

    #[DataProvider('foreignTagNames')]
    public function testAForeignTagNameIsNeverTurnedIntoARequest(string $tagName): void
    {
        $this->expectException(SocialNetworkUnreachableException::class);

        $this->source(new RoutedHttpClient([]))->request(self::socialFeed(SocialFeedKind::Tag, $tagName));
    }

    public function testAnAnswerMapsToThePageAndNamesItsNewestStatus(): void
    {
        $page = $this->pageOf(self::answered(self::statusesBody('109')));

        self::assertCount(1, $page->parsed->entries);
        self::assertSame('109', $page->newestId);
    }

    /** @return iterable<string, array{int}> */
    public static function refusals(): iterable
    {
        yield 'unauthorized' => [Response::HTTP_UNAUTHORIZED];
        yield 'forbidden' => [Response::HTTP_FORBIDDEN];
    }

    #[DataProvider('refusals')]
    public function testARefusedTokenInTheAnswerIsARefusedCredential(int $status): void
    {
        $this->expectException(SocialCredentialsRejectedException::class);

        $this->pageOf(self::answered('{}', $status));
    }

    public function testATooManyRequestsAnswerIsAThrottleCarryingItsDelay(): void
    {
        try {
            $this->pageOf(self::answered('{}', Response::HTTP_TOO_MANY_REQUESTS, ['retry-after' => ['60']]));
            self::fail('A 429 must not read as a page.');
        } catch (SocialNetworkThrottledException $exception) {
            self::assertSame(60, $exception->retryAfterSeconds);
        }
    }

    public function testNoAnswerIsAnUnreachableInstance(): void
    {
        $this->expectException(SocialNetworkUnreachableException::class);
        $this->expectExceptionMessage('The Mastodon instance could not be reached.');

        $this->pageOf(FetchOutcomeModel::failed(new FeedUnreachableException('https://mastodon.example: timed out')));
    }

    /** @return iterable<string, array{string}> */
    public static function bodiesThatAreNoList(): iterable
    {
        yield 'an object' => ['{"error":"odd"}'];
        yield 'an HTML page' => ['<!doctype html><html lang="en"><body>maintenance</body></html>'];
    }

    #[DataProvider('bodiesThatAreNoList')]
    public function testAnAnswerThatIsNoListReadsAsAnUnreachableNetwork(string $body): void
    {
        $this->expectException(SocialNetworkUnreachableException::class);

        $this->pageOf(self::answered($body));
    }

    private function pageOf(FetchOutcomeModel $outcome): TimelinePageModel
    {
        return $this->source(new RoutedHttpClient([]))->pageOf(self::socialFeed(SocialFeedKind::Home, ''), $outcome);
    }

    /** @param array<array<string>> $headers */
    private static function answered(
        string $body,
        int $status = Response::HTTP_OK,
        array $headers = [],
    ): FetchOutcomeModel {
        return FetchOutcomeModel::answered(new ApiResponseModel($status, $body, $headers));
    }

    private function source(RoutedHttpClient $http): MastodonTimelineSource
    {
        return new MastodonTimelineSource(SocialApiWiring::mastodonAccountApi($http->client()));
    }

    private static function socialFeed(SocialFeedKind $kind, string $remoteId): SocialFeed
    {
        return new SocialFeed(SocialAccounts::mastodon(), new Feed('social:mastodon:4:home'), $kind, $remoteId);
    }

    private static function statusesBody(string $newestId): string
    {
        return json_encode([[
            'id' => $newestId,
            'uri' => 'https://mastodon.example/users/bob/statuses/' . $newestId,
            'url' => 'https://mastodon.example/@bob/' . $newestId,
            'created_at' => '2026-10-12T10:00:00Z',
            'content' => '<p>Hello</p>',
            'account' => ['acct' => 'bob', 'display_name' => 'Bob', 'url' => 'https://mastodon.example/@bob'],
        ]], \JSON_THROW_ON_ERROR);
    }
}
