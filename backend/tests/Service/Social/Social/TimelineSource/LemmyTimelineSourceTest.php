<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\TimelineSource;

use App\Entity\SocialFeed;
use App\Enum\SocialFeedKind;
use App\Service\Outbound\Fetch\Model\ApiResponseModel;
use App\Service\Outbound\Fetch\Model\FetchOutcomeModel;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\TimelineSource\LemmyTimelineSource;
use App\Tests\Support\PrivateFeeds;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class LemmyTimelineSourceTest extends TestCase
{
    private const string POST_LIST = __DIR__ . '/../../../../Fixtures/lemmy/post-list.json';
    private const string LISTING = 'GET https://lemmy.example/api/v3/post/list?type_=%s&sort=New&limit=50';

    /** @return iterable<string, array{SocialFeedKind, string}> */
    public static function timelines(): iterable
    {
        yield 'subscribed' => [SocialFeedKind::Home, 'Subscribed'];
        yield 'local' => [SocialFeedKind::Local, 'Local'];
        yield 'all' => [SocialFeedKind::All, 'All'];
    }

    #[DataProvider('timelines')]
    public function testEachTimelineAsksItsListing(SocialFeedKind $kind, string $listing): void
    {
        $request = self::source()->requests(self::socialFeed($kind))[0];

        self::assertSame(sprintf(self::LISTING, $listing), $request->method . ' ' . $request->url);
        self::assertSame('Bearer ' . SocialAccounts::LEMMY_JWT, $request->headers['Authorization'] ?? null);
    }

    /** @return iterable<string, array{SocialFeedKind}> */
    public static function kindsLemmyHasNoTimelineFor(): iterable
    {
        yield 'list' => [SocialFeedKind::UserList];
        yield 'custom feed' => [SocialFeedKind::CustomFeed];
        yield 'author' => [SocialFeedKind::Author];
        yield 'tag' => [SocialFeedKind::Tag];
        yield 'trending' => [SocialFeedKind::Trending];
        yield 'search' => [SocialFeedKind::Search];
    }

    #[DataProvider('kindsLemmyHasNoTimelineFor')]
    public function testAKindLemmyHasNoTimelineForIsNeverTurnedIntoARequest(SocialFeedKind $kind): void
    {
        $this->expectException(SocialNetworkUnreachableException::class);
        $this->expectExceptionMessage('Lemmy has no such timeline.');

        self::source()->requests(self::socialFeed($kind));
    }

    /** @return iterable<string, array{int, string, class-string<\Throwable>}> */
    public static function refusals(): iterable
    {
        yield 'a missing post' => [
            Response::HTTP_BAD_REQUEST,
            '{"error":"couldnt_find_post"}',
            SocialNetworkUnreachableException::class,
        ];
        yield 'a community ban' => [
            Response::HTTP_BAD_REQUEST,
            '{"error":"banned_from_community"}',
            SocialNetworkUnreachableException::class,
        ];
        yield 'a lapsed session' => [
            Response::HTTP_BAD_REQUEST,
            '{"error":"not_logged_in"}',
            SocialCredentialsRejectedException::class,
        ];
        yield 'too many requests' => [Response::HTTP_TOO_MANY_REQUESTS, '{}', SocialNetworkThrottledException::class];
    }

    /** @param class-string<\Throwable> $thrown */
    #[DataProvider('refusals')]
    public function testARefusalInARoundIsUnreachable(int $status, string $body, string $thrown): void
    {
        $this->expectException($thrown);

        self::source()->accepted(
            self::socialFeed(SocialFeedKind::Home),
            FetchOutcomeModel::answered(new ApiResponseModel($status, $body, [])),
        );
    }

    public function testThePageIsBuiltFromThePosts(): void
    {
        $source = self::source();
        $home = self::socialFeed(SocialFeedKind::Home);
        $answer = new ApiResponseModel(Response::HTTP_OK, (string) file_get_contents(self::POST_LIST), []);

        $page = $source->pageOf($home, [$source->accepted($home, FetchOutcomeModel::answered($answer))]);

        self::assertCount(3, $page->parsed->entries);
    }

    private static function source(): LemmyTimelineSource
    {
        return new LemmyTimelineSource(
            SocialApiWiring::lemmyAccountApi((new RoutedHttpClient([]))->client()),
            SocialApiWiring::lemmyPostEntries(),
        );
    }

    private static function socialFeed(SocialFeedKind $kind): SocialFeed
    {
        return PrivateFeeds::of(SocialAccounts::lemmyOf(SocialAccounts::owner()), 1, $kind);
    }
}
