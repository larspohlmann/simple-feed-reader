<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\CandidateSource;

use App\Service\Social\Social\CandidateSource\LemmyCandidateSource;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Model\CollectedItemsModel;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialApiWiring;
use App\Tests\Support\TaggedItemOf;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

final class LemmyCandidateSourceTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/../../../../Fixtures/lemmy/community-list.json';
    private const string PAGE = 'GET https://lemmy.example/api/v3/community/list'
        . '?type_=Subscribed&sort=Old&limit=50&page=';
    private const int FULL_PAGE = 50;

    private function source(RoutedHttpClient $http): LemmyCandidateSource
    {
        return new LemmyCandidateSource(SocialApiWiring::lemmyAccountApi($http->client()));
    }

    private static function fixture(): MockResponse
    {
        return new MockResponse((string) file_get_contents(self::FIXTURE));
    }

    /** @param \Closure(int): int $sizeOfPage */
    private static function pagesOfCommunities(\Closure $sizeOfPage): RoutedHttpClient
    {
        $answer = static function (string $method, string $url) use ($sizeOfPage): MockResponse {
            $page = (int) substr($url, (int) strrpos($url, '=') + 1);
            $size = $sizeOfPage($page);
            $communities = array_map(
                static fn (int $index): array => ['community' => [
                    'name' => sprintf('p%dc%d', $page, $index),
                    'title' => 'C' . $index,
                    'actor_id' => sprintf('https://feddit.example/c/p%dc%d', $page, $index),
                ]],
                0 === $size ? [] : range(1, $size),
            );

            return new MockResponse(json_encode(['communities' => $communities], \JSON_THROW_ON_ERROR));
        };

        return RoutedHttpClient::answering($answer);
    }

    public function testTheNetworkKeyIsLemmy(): void
    {
        self::assertSame('lemmy', TaggedItemOf::index(LemmyCandidateSource::class));
    }

    public function testTheSubscribedCommunitiesAreTheFollows(): void
    {
        $http = new RoutedHttpClient([self::PAGE . '1' => self::fixture()]);

        $candidates = $this->source($http)->candidates(SocialAccounts::lemmyOf(SocialAccounts::owner()));

        self::assertSame(
            ['rss@feddit.example', 'linux@lemmy.example'],
            array_map(static fn ($follow): string => $follow->identity, $candidates->follows),
        );
        self::assertSame([], $candidates->lists);
        self::assertSame([], $candidates->customFeeds);
        self::assertSame([], $candidates->tags);
        self::assertFalse($candidates->truncated);
        self::assertSame([self::PAGE . '1'], array_map(
            static fn (string $url): string => 'GET ' . $url,
            $http->requestedUrls(),
        ));
        self::assertContains('Authorization: Bearer test-jwt-not-a-real-token', $http->requests[0]['headers']);
    }

    public function testCommunitiesArePagedUntilAShortPage(): void
    {
        $full = array_map(
            static fn (int $index): array => ['community' => [
                'name' => 'c' . $index,
                'title' => 'C' . $index,
                'actor_id' => 'https://feddit.example/c/c' . $index,
            ]],
            range(1, 50),
        );
        $http = new RoutedHttpClient([
            self::PAGE . '1' => new MockResponse(json_encode(['communities' => $full], \JSON_THROW_ON_ERROR)),
            self::PAGE . '2' => self::fixture(),
        ]);

        $candidates = $this->source($http)->candidates(SocialAccounts::lemmyOf(SocialAccounts::owner()));

        self::assertCount(52, $candidates->follows);
        self::assertCount(2, $http->requests);
    }

    public function testPagingStopsPastTheCapAndMarksTheFollowsTruncated(): void
    {
        $http = self::pagesOfCommunities(static fn (int $page): int => self::FULL_PAGE);

        $candidates = $this->source($http)->candidates(SocialAccounts::lemmyOf(SocialAccounts::owner()));

        self::assertCount(CollectedItemsModel::MAX_ITEMS, $candidates->follows);
        self::assertTrue($candidates->truncated);
        self::assertCount(CollectedItemsModel::MAX_ITEMS / self::FULL_PAGE + 1, $http->requests);
    }

    public function testExactlyTheCapIsNotTruncated(): void
    {
        $lastFullPage = CollectedItemsModel::MAX_ITEMS / self::FULL_PAGE;
        $http = self::pagesOfCommunities(static fn (int $page): int => $page <= $lastFullPage ? self::FULL_PAGE : 0);

        $candidates = $this->source($http)->candidates(SocialAccounts::lemmyOf(SocialAccounts::owner()));

        self::assertCount(CollectedItemsModel::MAX_ITEMS, $candidates->follows);
        self::assertFalse($candidates->truncated);
        self::assertCount($lastFullPage + 1, $http->requests);
    }

    public function testAnAnswerWithoutCommunitiesIsUnreachable(): void
    {
        $http = new RoutedHttpClient([self::PAGE . '1' => new MockResponse('{"error":"x"}')]);

        $this->expectException(SocialNetworkUnreachableException::class);
        $this->expectExceptionMessage('The Lemmy instance answered without communities.');

        $this->source($http)->candidates(SocialAccounts::lemmyOf(SocialAccounts::owner()));
    }

    public function testCollectionsAskNothing(): void
    {
        $http = new RoutedHttpClient([]);

        $collections = $this->source($http)->collections(SocialAccounts::lemmyOf(SocialAccounts::owner()));

        self::assertSame([], $collections->lists);
        self::assertSame([], $collections->customFeeds);
        self::assertSame([], $http->requests);
    }
}
