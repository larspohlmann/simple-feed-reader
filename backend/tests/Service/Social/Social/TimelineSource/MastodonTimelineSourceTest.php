<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\TimelineSource;

use App\Entity\Feed;
use App\Entity\SocialFeed;
use App\Enum\SocialFeedKind;
use App\Service\Outbound\Fetch\Exception\FeedUnreachableException;
use App\Service\Outbound\Fetch\Model\ApiResponseModel;
use App\Service\Outbound\Fetch\Model\ApiRequestModel;
use App\Service\Outbound\Fetch\Model\FetchOutcomeModel;
use App\Service\Ingestion\Parser\Model\ParsedEntryModel;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Model\TimelinePageModel;
use App\Service\Social\Social\TimelineSource\MastodonTimelineSource;
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
        $request = $this->source(new RoutedHttpClient([]))->requests(self::socialFeed(SocialFeedKind::Home, ''))[0];

        self::assertSame(self::HOME, $request->method . ' ' . $request->url);
        self::assertSame('Bearer ' . SocialAccounts::ACCESS_TOKEN, $request->headers['Authorization'] ?? null);
    }

    public function testAListRequestAsksOnlyForWhatCameAfterTheNewestSeenStatus(): void
    {
        $list = self::socialFeed(SocialFeedKind::UserList, '42');
        $list->recordNewestSeen('100');

        $request = $this->source(new RoutedHttpClient([]))->requests($list)[0];

        self::assertSame('https://mastodon.example/api/v1/timelines/list/42?limit=40&min_id=100', $request->url);
    }

    #[DataProvider('foreignListIds')]
    public function testAForeignListIdIsNeverTurnedIntoARequest(string $listId): void
    {
        $this->expectException(SocialNetworkUnreachableException::class);

        $this->source(new RoutedHttpClient([]))->requests(self::socialFeed(SocialFeedKind::UserList, $listId));
    }

    public function testATagRequestReadsTheTagTimelineAfterTheNewestSeenStatus(): void
    {
        $tag = self::socialFeed(SocialFeedKind::Tag, 'caturday');
        $tag->recordNewestSeen('100');

        $request = $this->source(new RoutedHttpClient([]))->requests($tag)[0];

        self::assertSame('https://mastodon.example/api/v1/timelines/tag/caturday?limit=40&min_id=100', $request->url);
        self::assertSame('Bearer ' . SocialAccounts::ACCESS_TOKEN, $request->headers['Authorization'] ?? null);
    }

    public function testTrendingAsksForTheCurrentRankingAndNeverForWhatCameAfterTheNewestSeen(): void
    {
        $trending = self::socialFeed(SocialFeedKind::Trending, '');
        $trending->recordNewestSeen('100');

        $request = $this->source(new RoutedHttpClient([]))->requests($trending)[0];

        self::assertSame('https://mastodon.example/api/v1/trends/statuses?limit=40', $request->url);
    }

    public function testASearchAsksForTheLatestStatusesMatching(): void
    {
        $request = $this->source(new RoutedHttpClient([]))
            ->requests(self::socialFeed(SocialFeedKind::Search, 'rss reader'))[0];

        self::assertSame(
            'https://mastodon.example/api/v2/search?type=statuses&resolve=false&limit=40&q=rss%20reader',
            $request->url,
        );
    }

    public function testASearchAsksOnlyForWhatCameAfterTheNewestSeenStatus(): void
    {
        $search = self::socialFeed(SocialFeedKind::Search, 'rss reader');
        $search->recordNewestSeen('110');

        $request = $this->source(new RoutedHttpClient([]))->requests($search)[0];

        self::assertSame(
            'https://mastodon.example/api/v2/search?type=statuses&resolve=false&limit=40&q=rss%20reader&min_id=110',
            $request->url,
        );
    }

    public function testASearchAnswerMapsToItsStatusesWithoutRepliesOrStatusesTheQueryDoesNotMatch(): void
    {
        $body = self::searchBody(
            self::searchStatus('111', null),
            self::searchStatus('112', '100'),
            self::searchStatus('110', null, '<p>Goodbye</p>'),
        );

        $source = $this->source(new RoutedHttpClient([]));
        $search = self::socialFeed(SocialFeedKind::Search, 'hello');
        $page = $source->pageOf($search, [$source->accepted($search, self::answered($body))]);

        self::assertSame(['https://mastodon.example/@bob/111'], array_map(
            static fn (ParsedEntryModel $entry): ?string => $entry->url,
            $page->parsed->entries,
        ));
        self::assertSame('111', $page->newestId);
    }

    public function testASearchAsksOncePerAlternativeEachAfterTheNewestSeenStatus(): void
    {
        $search = self::socialFeed(SocialFeedKind::Search, '(rss || atom) -json');
        $search->recordNewestSeen('110');

        $requests = $this->source(new RoutedHttpClient([]))->requests($search);

        self::assertSame([
            'https://mastodon.example/api/v2/search?type=statuses&resolve=false&limit=40&q=rss%20-json&min_id=110',
            'https://mastodon.example/api/v2/search?type=statuses&resolve=false&limit=40&q=atom%20-json&min_id=110',
        ], array_map(static fn (ApiRequestModel $request): string => $request->url, $requests));
    }

    public function testASearchTooBroadForMastodonIsNeverTurnedIntoARequest(): void
    {
        $this->expectException(SocialNetworkUnreachableException::class);
        $this->expectExceptionMessage('more than 8');

        $this->source(new RoutedHttpClient([]))
            ->requests(self::socialFeed(SocialFeedKind::Search, '(a || b || c) (d || e || f)'));
    }

    public function testTheAnswersOfASearchsAlternativesMergeIntoOnePageHoldingEachStatusOnce(): void
    {
        $source = $this->source(new RoutedHttpClient([]));
        $search = self::socialFeed(SocialFeedKind::Search, '(rss || atom)');
        $first = self::searchBody(
            self::searchStatus('111', null, '<p>rss</p>'),
            self::searchStatus('110', null, '<p>atom</p>'),
        );
        $second = self::searchBody(
            self::searchStatus('111', null, '<p>rss</p>'),
            self::searchStatus('109', null, '<p>atom</p>'),
        );

        $page = $source->pageOf($search, [
            $source->accepted($search, self::answered($first)),
            $source->accepted($search, self::answered($second)),
        ]);

        self::assertSame(
            [
                'https://mastodon.example/@bob/111',
                'https://mastodon.example/@bob/110',
                'https://mastodon.example/@bob/109',
            ],
            array_map(static fn (ParsedEntryModel $entry): ?string => $entry->url, $page->parsed->entries),
        );
        self::assertSame('111', $page->newestId);
    }

    /** @param array<string, mixed> ...$statuses */
    private static function searchBody(array ...$statuses): string
    {
        return json_encode(['accounts' => [], 'hashtags' => [], 'statuses' => $statuses], \JSON_THROW_ON_ERROR);
    }

    /** @return iterable<string, array{SocialFeedKind}> */
    public static function kindsMastodonHasNoTimelineFor(): iterable
    {
        yield 'author' => [SocialFeedKind::Author];
        yield 'custom feed' => [SocialFeedKind::CustomFeed];
        yield 'local' => [SocialFeedKind::Local];
        yield 'all' => [SocialFeedKind::All];
    }

    #[DataProvider('kindsMastodonHasNoTimelineFor')]
    public function testAKindMastodonHasNoTimelineForIsNeverTurnedIntoARequest(SocialFeedKind $kind): void
    {
        $this->expectException(SocialNetworkUnreachableException::class);

        $this->source(new RoutedHttpClient([]))->requests(self::socialFeed($kind, 'at://did:plc:alice'));
    }

    public function testANonLatinTagIsPercentEncodedInThePath(): void
    {
        $request = $this->source(new RoutedHttpClient([]))->requests(self::socialFeed(SocialFeedKind::Tag, '日本語'))[0];

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

        $this->source(new RoutedHttpClient([]))->requests(self::socialFeed(SocialFeedKind::Tag, $tagName));
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
        $source = $this->source(new RoutedHttpClient([]));
        $home = self::socialFeed(SocialFeedKind::Home, '');

        return $source->pageOf($home, [$source->accepted($home, $outcome)]);
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
        return json_encode([self::searchStatus($newestId, null)], \JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private static function searchStatus(string $id, ?string $inReplyToId, string $content = '<p>Hello</p>'): array
    {
        return [
            'id' => $id,
            'uri' => 'https://mastodon.example/users/bob/statuses/' . $id,
            'url' => 'https://mastodon.example/@bob/' . $id,
            'created_at' => '2026-10-12T10:00:00Z',
            'content' => $content,
            'in_reply_to_id' => $inReplyToId,
            'account' => ['acct' => 'bob', 'display_name' => 'Bob', 'url' => 'https://mastodon.example/@bob'],
        ];
    }
}
