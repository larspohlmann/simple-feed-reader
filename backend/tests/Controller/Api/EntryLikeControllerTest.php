<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\SocialAccount;
use App\Entity\SocialLike;
use App\Enum\SocialAccountStatus;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\NetworkFeedSubscriptions;
use App\Tests\Support\RoutesSocialApis;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class EntryLikeControllerTest extends ApiTestCase
{
    use RoutesSocialApis;

    private const array ROUTED_HOSTS = ['mastodon.example'];
    private const string SEARCH = 'GET https://mastodon.example/api/v2/search?q='
        . 'https%3A%2F%2Fmastodon.social%2F%40Gargron%2F110&resolve=true&type=statuses&limit=1';
    private const string FAVOURITE = 'POST https://mastodon.example/api/v1/statuses/110/favourite';
    private const string UNFAVOURITE = 'POST https://mastodon.example/api/v1/statuses/110/unfavourite';

    /** Must match framework.rate_limiter.social_like.limit in rate_limiter.yaml. */
    private const int LIKE_BUDGET = 60;

    protected function setUp(): void
    {
        self::bootKernel();
        $rateLimiterCache = self::getContainer()->get('test.cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $rateLimiterCache);
        $rateLimiterCache->clear();
        self::ensureKernelShutdown();
    }

    public function testAnAnonymousCallerIsRefused(): void
    {
        $client = static::createClient();

        $client->request('PUT', '/api/entries/1/like');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testALikeReachesTheOriginAndTheEntryReadsAsLiked(): void
    {
        $client = $this->clientRouting(
            self::ROUTED_HOSTS,
            [self::SEARCH => self::found(), self::FAVOURITE => self::accepted()],
        );
        $user = $this->signIn($client, 'like-put@example.test');
        $this->mastodonAccountOf($user);
        $entryId = $this->mastodonPostFor($user);

        $client->request('PUT', sprintf('/api/entries/%d/like', $entryId));

        self::assertResponseIsSuccessful();
        self::assertSame(['isLiked' => true], $this->payload($client));
        self::assertSame([self::SEARCH, self::FAVOURITE], $this->routesAsked());
        self::assertSame(1, $this->likeRows());
        self::assertSame(
            ['network' => 'mastodon', 'isLiked' => true, 'canLike' => true],
            $this->socialOf($client, $entryId),
        );
    }

    public function testASecondLikeCallsNoNetwork(): void
    {
        $client = $this->clientRouting(
            self::ROUTED_HOSTS,
            [self::SEARCH => self::found(), self::FAVOURITE => self::accepted()],
        );
        $user = $this->signIn($client, 'like-twice@example.test');
        $this->mastodonAccountOf($user);
        $entryId = $this->mastodonPostFor($user);

        $client->request('PUT', sprintf('/api/entries/%d/like', $entryId));
        $client->request('PUT', sprintf('/api/entries/%d/like', $entryId));

        self::assertSame(['isLiked' => true], $this->payload($client));
        self::assertCount(2, $this->http->requests);
    }

    public function testAnUnlikeTakesTheLikeBack(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::SEARCH => self::found(),
            self::FAVOURITE => self::accepted(),
            self::UNFAVOURITE => self::accepted(),
        ]);
        $user = $this->signIn($client, 'like-delete@example.test');
        $this->mastodonAccountOf($user);
        $entryId = $this->mastodonPostFor($user);
        $client->request('PUT', sprintf('/api/entries/%d/like', $entryId));

        $client->request('DELETE', sprintf('/api/entries/%d/like', $entryId));

        self::assertResponseIsSuccessful();
        self::assertSame(['isLiked' => false], $this->payload($client));
        self::assertSame([self::SEARCH, self::FAVOURITE, self::UNFAVOURITE], $this->routesAsked());
        self::assertSame(0, $this->likeRows());
        self::assertSame(
            ['network' => 'mastodon', 'isLiked' => false, 'canLike' => true],
            $this->socialOf($client, $entryId),
        );
    }

    public function testUnlikingAPostNeverLikedCallsNoNetwork(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $user = $this->signIn($client, 'like-delete-none@example.test');
        $this->mastodonAccountOf($user);

        $client->request('DELETE', sprintf('/api/entries/%d/like', $this->mastodonPostFor($user)));

        self::assertSame(['isLiked' => false], $this->payload($client));
        self::assertSame([], $this->http->requests);
    }

    public function testAnOrdinaryArticleIsNoPostToLike(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $user = $this->signIn($client, 'like-plain@example.test');
        $this->mastodonAccountOf($user);
        $fixtures = new NetworkFeedSubscriptions($this->entityManager());
        $entryId = $fixtures->post(
            $fixtures->subscribe($user, NetworkFeedSubscriptions::PLAIN),
            self::MASTODON_POST_URL,
            '2026-10-12T10:00:00Z',
        )->requireId();

        $client->request('PUT', sprintf('/api/entries/%d/like', $entryId));

        $this->assertRejected($client, Response::HTTP_CONFLICT);
        self::assertSame('social_post_unsupported', $this->payload($client)['type']);
        self::assertNull($this->socialOf($client, $entryId));
    }

    public function testAPostOnANetworkWithoutAnAccountIsRefusedAndCannotBeLiked(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $user = $this->signIn($client, 'like-no-account@example.test');
        $entryId = $this->mastodonPostFor($user);

        $client->request('PUT', sprintf('/api/entries/%d/like', $entryId));

        $this->assertRejected($client, Response::HTTP_CONFLICT);
        self::assertSame('social_account_missing', $this->payload($client)['type']);
        self::assertSame(
            ['network' => 'mastodon', 'isLiked' => false, 'canLike' => false],
            $this->socialOf($client, $entryId),
        );
    }

    public function testAnotherUsersEntryReadsAsMissing(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $stranger = $this->factory()->create('like-stranger@example.test');
        $entryId = $this->mastodonPostFor($stranger);
        $user = $this->signIn($client, 'like-own@example.test');
        $this->mastodonAccountOf($user);

        $client->request('PUT', sprintf('/api/entries/%d/like', $entryId));

        $this->assertRejected($client, Response::HTTP_NOT_FOUND);
        self::assertSame([], $this->http->requests);
    }

    public function testARefusedTokenMarksTheAccountAsNeedingAReconnect(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::SEARCH => new MockResponse('{}', ['http_code' => Response::HTTP_UNAUTHORIZED]),
        ]);
        $user = $this->signIn($client, 'like-refused@example.test');
        $accountId = $this->mastodonAccountOf($user)->requireId();

        $client->request('PUT', sprintf('/api/entries/%d/like', $this->mastodonPostFor($user)));

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('social_credentials_rejected', $this->payload($client)['type']);
        self::assertSame(0, $this->likeRows());
        $this->entityManager()->clear();
        self::assertSame(
            SocialAccountStatus::NeedsReconnect,
            $this->entityManager()->find(SocialAccount::class, $accountId)?->getStatus(),
        );
    }

    public function testAForbiddenLikeRefusesThePostAndKeepsTheAccountConnected(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::SEARCH => self::found(),
            self::FAVOURITE => new MockResponse('{}', ['http_code' => Response::HTTP_FORBIDDEN]),
        ]);
        $user = $this->signIn($client, 'like-forbidden@example.test');
        $accountId = $this->mastodonAccountOf($user)->requireId();

        $client->request('PUT', sprintf('/api/entries/%d/like', $this->mastodonPostFor($user)));

        $this->assertRejected($client, Response::HTTP_CONFLICT);
        self::assertSame('social_post_refused', $this->payload($client)['type']);
        self::assertSame(0, $this->likeRows());
        $this->entityManager()->clear();
        self::assertSame(
            SocialAccountStatus::Connected,
            $this->entityManager()->find(SocialAccount::class, $accountId)?->getStatus(),
        );
    }

    public function testAForbiddenSearchKeepsTheAccountConnected(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::SEARCH => new MockResponse(
                '{"error":"This action is outside the authorized scopes"}',
                ['http_code' => Response::HTTP_FORBIDDEN],
            ),
        ]);
        $user = $this->signIn($client, 'like-scope@example.test');
        $accountId = $this->mastodonAccountOf($user)->requireId();

        $client->request('PUT', sprintf('/api/entries/%d/like', $this->mastodonPostFor($user)));

        $this->assertRejected($client, Response::HTTP_CONFLICT);
        self::assertSame('social_token_scope_missing', $this->payload($client)['type']);
        self::assertSame(0, $this->likeRows());
        $this->entityManager()->clear();
        self::assertSame(
            SocialAccountStatus::Connected,
            $this->entityManager()->find(SocialAccount::class, $accountId)?->getStatus(),
        );
    }

    public function testAPostTheInstanceCannotFindIsNoLongerAvailable(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [self::SEARCH => new MockResponse('{"statuses":[]}')]);
        $user = $this->signIn($client, 'like-gone@example.test');
        $this->mastodonAccountOf($user);

        $client->request('PUT', sprintf('/api/entries/%d/like', $this->mastodonPostFor($user)));

        $this->assertRejected($client, Response::HTTP_NOT_FOUND);
        self::assertSame('social_post_not_found', $this->payload($client)['type']);
        self::assertSame('Post no longer available', $this->payload($client)['title']);
        self::assertSame(0, $this->likeRows());
    }

    public function testALikeAndAnUnlikeDrawOnOneBudget(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $user = $this->signIn($client, 'like-budget@example.test');
        $this->mastodonAccountOf($user);
        $path = sprintf('/api/entries/%d/like', $this->mastodonPostFor($user));
        for ($attempt = 0; $attempt < self::LIKE_BUDGET; ++$attempt) {
            $client->request('DELETE', $path);
            self::assertResponseIsSuccessful();
        }

        $client->request('PUT', $path);

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        self::assertSame([], $this->http->requests);
    }

    public function testAnUnknownEntryReadsAsMissing(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $this->signIn($client, 'like-unknown@example.test');

        $client->request('PUT', '/api/entries/999999/like');

        $this->assertRejected($client, Response::HTTP_NOT_FOUND);
    }

    private static function found(): MockResponse
    {
        return new MockResponse('{"statuses":[{"id":"110"}]}');
    }

    private static function accepted(): MockResponse
    {
        return new MockResponse('{"id":"110"}');
    }

    /** @return array{network: string, isLiked: bool, canLike: bool}|null */
    private function socialOf(KernelBrowser $client, int $entryId): ?array
    {
        $client->request('GET', sprintf('/api/entries/%d', $entryId));
        self::assertResponseIsSuccessful();
        $entry = $this->payload($client)['entry'];
        self::assertIsArray($entry);
        $social = $entry['social'];
        self::assertTrue(null === $social || is_array($social));

        /** @var array{network: string, isLiked: bool, canLike: bool}|null $social */
        return $social;
    }

    private function likeRows(): int
    {
        $this->entityManager()->clear();

        return $this->entityManager()->getRepository(SocialLike::class)->count([]);
    }
}
