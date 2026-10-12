<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\TimelineSource;

use App\Entity\Feed;
use App\Entity\SocialFeed;
use App\Enum\SocialFeedKind;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\MastodonApiClient;
use App\Service\Social\StoredCredential;
use App\Service\Social\TimelineSource\MastodonTimelineSource;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StaticDnsResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class MastodonTimelineSourceTest extends TestCase
{
    private const string HOME = 'GET https://mastodon.example/api/v1/timelines/home?limit=40';

    public function testTheHomeTimelineIsReadWithTheTokenAndNamesItsNewestStatus(): void
    {
        $http = new RoutedHttpClient([self::HOME => self::statuses('109')]);

        $page = $this->source($http)->page(self::socialFeed(SocialFeedKind::Home, null));

        self::assertCount(1, $page->parsed->entries);
        self::assertSame('109', $page->newestId);
        self::assertContains('Authorization: Bearer ' . SocialAccounts::ACCESS_TOKEN, $http->requests[0]['headers']);
    }

    public function testAListAsksOnlyForWhatCameAfterTheNewestSeenStatus(): void
    {
        $http = new RoutedHttpClient([
            'GET https://mastodon.example/api/v1/timelines/list/42?limit=40&min_id=100' => self::statuses('120'),
        ]);
        $list = self::socialFeed(SocialFeedKind::UserList, '42');
        $list->recordNewestSeen('100');

        self::assertSame('120', $this->source($http)->page($list)->newestId);
    }

    public function testARefusedTokenIsARefusedCredential(): void
    {
        $http = new RoutedHttpClient([
            self::HOME => new MockResponse('{}', ['http_code' => Response::HTTP_UNAUTHORIZED]),
        ]);

        $this->expectException(SocialCredentialsRejectedException::class);

        $this->source($http)->page(self::socialFeed(SocialFeedKind::Home, null));
    }

    public function testAnAnswerThatIsNoListIsAnUnreachableNetwork(): void
    {
        $http = new RoutedHttpClient([self::HOME => new MockResponse('{"error":"odd"}')]);

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->source($http)->page(self::socialFeed(SocialFeedKind::Home, null));
    }

    public function testAnHtmlAnswerIsAnUnreachableNetwork(): void
    {
        $maintenancePage = '<!doctype html><html lang="en"><body>maintenance</body></html>';
        $http = new RoutedHttpClient([self::HOME => new MockResponse($maintenancePage)]);

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->source($http)->page(self::socialFeed(SocialFeedKind::Home, null));
    }

    #[DataProvider('foreignListIds')]
    public function testAListIdThatIsNotANumberIsNeverRequested(string $listId): void
    {
        $http = new RoutedHttpClient([]);

        try {
            $this->source($http)->page(self::socialFeed(SocialFeedKind::UserList, $listId));
            self::fail('A foreign list id must not be requested.');
        } catch (SocialNetworkUnreachableException) {
            self::assertSame([], $http->requests);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function foreignListIds(): iterable
    {
        yield 'parent path' => ['..'];
        yield 'empty' => [''];
        yield 'letters' => ['abc'];
        yield 'too long' => [str_repeat('1', 33)];
    }

    private function source(RoutedHttpClient $http): MastodonTimelineSource
    {
        $guard = new UrlGuard(new StaticDnsResolver(['mastodon.example' => ['93.184.216.34']]), new IpValidator());

        return new MastodonTimelineSource(
            new MastodonApiClient(FetchWiring::guardedApiClient($http->client(), $guard)),
            new StoredCredential(SocialAccounts::cipher()),
        );
    }

    private static function socialFeed(SocialFeedKind $kind, ?string $remoteId): SocialFeed
    {
        return new SocialFeed(SocialAccounts::mastodon(), new Feed('social:mastodon:4:home'), $kind, $remoteId);
    }

    private static function statuses(string $newestId): MockResponse
    {
        return new MockResponse(json_encode([[
            'id' => $newestId,
            'uri' => 'https://mastodon.example/users/bob/statuses/' . $newestId,
            'url' => 'https://mastodon.example/@bob/' . $newestId,
            'created_at' => '2026-10-12T10:00:00Z',
            'content' => '<p>Hello</p>',
            'account' => ['acct' => 'bob', 'display_name' => 'Bob', 'url' => 'https://mastodon.example/@bob'],
        ]], \JSON_THROW_ON_ERROR));
    }
}
