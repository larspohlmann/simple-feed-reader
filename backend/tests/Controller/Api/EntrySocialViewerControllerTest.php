<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Entry;
use App\Entity\SocialAccount;
use App\Entity\SocialLike;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Service\Crypto\InstanceSecretCipher;
use App\Service\SavedPosts\Support\SavedFeedUrl;
use App\Service\Social\Crypto\SocialCredentialCipher;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\NetworkFeedSubscriptions;
use App\Tests\Support\RoutesSocialApis;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class EntrySocialViewerControllerTest extends ApiTestCase
{
    use RoutesSocialApis;

    private const array ROUTED_HOSTS = ['mastodon.example', 'mastodon.social'];
    private const string SEARCH = 'GET https://mastodon.example/api/v2/search?q='
        . 'https%3A%2F%2Fmastodon.social%2F%40Gargron%2F110&resolve=true&type=statuses&limit=1';
    private const string FAVOURITE = 'POST https://mastodon.example/api/v1/statuses/110/favourite';
    private const string ORIGIN_STATUS = 'GET https://mastodon.social/api/v1/statuses/110';
    private const array BLUESKY_HOSTS = ['pds.example.test', 'plc.directory'];
    private const string BLUESKY_POST = 'at://did:plc:bob/app.bsky.feed.post/3abc';
    private const string BLUESKY_SESSION = 'POST https://pds.example.test/xrpc/com.atproto.server.createSession';
    private const string BLUESKY_GET_POSTS = 'GET https://pds.example.test/xrpc/app.bsky.feed.getPosts?uris='
        . 'at%3A%2F%2Fdid%3Aplc%3Abob%2Fapp.bsky.feed.post%2F3abc';
    private const string BOB_DID_DOCUMENT = 'GET https://plc.directory/did:plc:bob';
    private const string BOB_GET_RECORD = 'GET https://pds.example.test/xrpc/com.atproto.repo.getRecord'
        . '?repo=did%3Aplc%3Abob&collection=app.bsky.feed.post&rkey=3abc';

    /** Must match framework.rate_limiter.social_viewer.limit in rate_limiter.yaml. */
    private const int VIEWER_BUDGET = 120;

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

        $client->request('GET', '/api/entries/1/social/viewer');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testOpeningAPostLikedAndBoostedOnTheNetworkSaysSoAndStoresTheLikeForTheList(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::SEARCH => self::foundStatus([
                'favourited' => true,
                'reblogged' => true,
                'quote_approval' => ['current_user' => 'automatic'],
            ]),
        ]);
        $user = $this->signIn($client, 'viewer-liked@example.test');
        $this->mastodonAccountOf($user);
        $entryId = $this->mastodonPostFor($user);

        $client->request('GET', $this->viewerPath($entryId));

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['isLiked' => true, 'isReposted' => true, 'canRepost' => true, 'canReply' => true, 'canQuote' => true],
            $this->payload($client),
        );
        self::assertSame([self::SEARCH], $this->routesAsked());
        self::assertSame(
            ['network' => 'mastodon', 'isLiked' => true, 'canLike' => true, 'isOwn' => false],
            $this->socialOf($client, $entryId),
        );
    }

    public function testOpeningAPostUnlikedOnTheNetworkForgetsTheStoredLike(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::SEARCH => self::foundStatus([]),
            self::FAVOURITE => new MockResponse('{"id":"110"}'),
        ]);
        $user = $this->signIn($client, 'viewer-unliked@example.test');
        $this->mastodonAccountOf($user);
        $entryId = $this->mastodonPostFor($user);
        $client->request('PUT', sprintf('/api/entries/%d/like', $entryId));

        $client->request('GET', $this->viewerPath($entryId));

        self::assertSame(
            ['isLiked' => false, 'isReposted' => false, 'canRepost' => true, 'canReply' => true, 'canQuote' => false],
            $this->payload($client),
        );
        self::assertSame(0, $this->likeRows());
    }

    public function testADirectPostCannotBeBoosted(): void
    {
        $client = $this->clientRouting(
            self::ROUTED_HOSTS,
            [self::SEARCH => self::foundStatus(['visibility' => 'direct'])],
        );
        $user = $this->signIn($client, 'viewer-direct@example.test');
        $this->mastodonAccountOf($user);

        $client->request('GET', $this->viewerPath($this->mastodonPostFor($user)));

        self::assertSame(
            ['isLiked' => false, 'isReposted' => false, 'canRepost' => false, 'canReply' => true, 'canQuote' => false],
            $this->payload($client),
        );
    }

    public function testThePostOfAReaderWithoutAnAccountIsRefused(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $user = $this->signIn($client, 'viewer-no-account@example.test');

        $client->request('GET', $this->viewerPath($this->mastodonPostFor($user)));

        $this->assertRejected($client, Response::HTTP_CONFLICT);
        self::assertSame('social_account_missing', $this->payload($client)['type']);
    }

    public function testAnotherUsersEntryReadsAsMissing(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $entryId = $this->mastodonPostFor($this->factory()->create('viewer-stranger@example.test'));
        $user = $this->signIn($client, 'viewer-own@example.test');
        $this->mastodonAccountOf($user);

        $client->request('GET', $this->viewerPath($entryId));

        $this->assertRejected($client, Response::HTTP_NOT_FOUND);
        self::assertSame([], $this->http->requests);
    }

    public function testTheViewerStateHasItsOwnBudget(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $user = $this->signIn($client, 'viewer-budget@example.test');
        $entryId = $this->mastodonPostFor($user);
        for ($attempt = 0; $attempt < self::VIEWER_BUDGET; ++$attempt) {
            $client->request('GET', $this->viewerPath($entryId));
            $this->assertRejected($client, Response::HTTP_CONFLICT);
        }

        $client->request('GET', $this->viewerPath($entryId));

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        $client->request('DELETE', sprintf('/api/entries/%d/like', $entryId));
        self::assertResponseIsSuccessful();
    }

    public function testAPostItsHomeServerNoLongerHasIsRemovedHereAndSaysSo(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::SEARCH => self::noStatus(),
            self::ORIGIN_STATUS => new MockResponse('{}', ['http_code' => Response::HTTP_NOT_FOUND]),
        ]);
        $user = $this->signIn($client, 'viewer-deleted@example.test');
        $this->mastodonAccountOf($user);
        $entryId = $this->mastodonPostFor($user);

        $client->request('GET', $this->viewerPath($entryId));

        $this->assertRejected($client, Response::HTTP_GONE);
        self::assertSame('social_post_removed', $this->payload($client)['type']);
        self::assertSame([self::SEARCH, self::ORIGIN_STATUS], $this->routesAsked());
        $this->entityManager()->clear();
        self::assertNull($this->entityManager()->find(Entry::class, $entryId));
    }

    public function testABlueskyPostItsPdsNoLongerHasIsRemovedHere(): void
    {
        $client = $this->clientRouting(self::BLUESKY_HOSTS, [
            self::BLUESKY_SESSION => SocialApiWiring::blueskySessionAnswer('jwt-1'),
            self::BLUESKY_GET_POSTS => new MockResponse('{"posts":[]}'),
            self::BOB_DID_DOCUMENT => new MockResponse(
                '{"id":"did:plc:bob","service":[{"id":"#atproto_pds",'
                . '"type":"AtprotoPersonalDataServer","serviceEndpoint":"https://pds.example.test"}]}',
            ),
            self::BOB_GET_RECORD => new MockResponse(
                '{"error":"RecordNotFound","message":"x"}',
                ['http_code' => Response::HTTP_BAD_REQUEST],
            ),
        ]);
        $user = $this->signIn($client, 'viewer-bluesky-deleted@example.test');
        $this->blueskyAccountOf($user);
        $entryId = $this->blueskyPostFor($user);

        $client->request('GET', $this->viewerPath($entryId));

        $this->assertRejected($client, Response::HTTP_GONE);
        self::assertSame('social_post_removed', $this->payload($client)['type']);
        self::assertContains(self::BOB_GET_RECORD, $this->routesAsked());
        $this->entityManager()->clear();
        self::assertNull($this->entityManager()->find(Entry::class, $entryId));
    }

    public function testASavedCopyOutlivesItsDeletedOriginal(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::SEARCH => self::noStatus(),
            self::ORIGIN_STATUS => new MockResponse('{}', ['http_code' => Response::HTTP_NOT_FOUND]),
        ]);
        $user = $this->signIn($client, 'viewer-saved@example.test');
        $this->mastodonAccountOf($user);
        $entryId = $this->savedMastodonPostFor($user);

        $client->request('GET', $this->viewerPath($entryId));

        $this->assertRejected($client, Response::HTTP_NOT_FOUND);
        self::assertSame('social_post_not_found', $this->payload($client)['type']);
        self::assertSame([self::SEARCH], $this->routesAsked());
        $this->entityManager()->clear();
        self::assertNotNull($this->entityManager()->find(Entry::class, $entryId));
    }

    #[DataProvider('unprovenDeletions')]
    public function testAPostItsHomeServerDoesNotProveDeletedIsKept(MockResponse $originAnswer): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::SEARCH => self::noStatus(),
            self::ORIGIN_STATUS => $originAnswer,
        ]);
        $user = $this->signIn($client, 'viewer-unproven@example.test');
        $this->mastodonAccountOf($user);
        $entryId = $this->mastodonPostFor($user);

        $client->request('GET', $this->viewerPath($entryId));

        $this->assertRejected($client, Response::HTTP_NOT_FOUND);
        self::assertSame('social_post_not_found', $this->payload($client)['type']);
        $this->entityManager()->clear();
        self::assertNotNull($this->entityManager()->find(Entry::class, $entryId));
    }

    /** @return iterable<string, array{MockResponse}> */
    public static function unprovenDeletions(): iterable
    {
        yield 'still there for anyone' => [new MockResponse('{"id":"110"}')];
        yield 'closed to anonymous readers' => [new MockResponse('{}', ['http_code' => Response::HTTP_UNAUTHORIZED])];
        yield 'forbidden' => [new MockResponse('{}', ['http_code' => Response::HTTP_FORBIDDEN])];
        yield 'failing' => [new MockResponse('{}', ['http_code' => Response::HTTP_INTERNAL_SERVER_ERROR])];
        yield 'unreachable' => [new MockResponse('', ['error' => 'Connection refused'])];
    }

    private function viewerPath(int $entryId): string
    {
        return sprintf('/api/entries/%d/social/viewer', $entryId);
    }

    /** @param array<string, mixed> $fields */
    private static function foundStatus(array $fields): MockResponse
    {
        return new MockResponse(json_encode(['statuses' => [['id' => '110', ...$fields]]], \JSON_THROW_ON_ERROR));
    }

    private function savedMastodonPostFor(User $user): int
    {
        $fixtures = new NetworkFeedSubscriptions($this->entityManager());
        $saved = $fixtures->subscribe(
            $user,
            SavedFeedUrl::of($user->requireId(), SocialNetwork::Mastodon),
            SourceFormat::SAVED,
        );

        return $fixtures->post($saved, self::MASTODON_POST_URL, '2026-10-12T10:00:00Z')->requireId();
    }

    private function blueskyPostFor(User $user): int
    {
        $fixtures = new NetworkFeedSubscriptions($this->entityManager());
        $profile = $fixtures->subscribe($user, NetworkFeedSubscriptions::BLUESKY_PROFILE);

        return $fixtures->post($profile, self::BLUESKY_POST, '2026-10-12T10:00:00Z')->requireId();
    }

    private function blueskyAccountOf(User $user): void
    {
        /** @var InstanceSecretCipher $instanceCipher */
        $instanceCipher = self::getContainer()->get(InstanceSecretCipher::class);
        $this->entityManager()->persist(new SocialAccount(
            $user,
            SocialNetwork::Bluesky,
            'did:plc:alice',
            '@alice.bsky.social',
            'https://pds.example.test',
            (new SocialCredentialCipher($instanceCipher))->seal($user->requireId(), SocialAccounts::APP_PASSWORD),
            'mnop',
            new \DateTimeImmutable('2026-10-12 10:00:00'),
        ));
        $this->entityManager()->flush();
    }

    private static function noStatus(): MockResponse
    {
        return new MockResponse('{"statuses":[]}');
    }

    /** @return array{network: string, isLiked: bool, canLike: bool, isOwn: bool}|null */
    private function socialOf(KernelBrowser $client, int $entryId): ?array
    {
        $client->request('GET', sprintf('/api/entries/%d', $entryId));
        self::assertResponseIsSuccessful();
        $entry = $this->payload($client)['entry'];
        self::assertIsArray($entry);
        $social = $entry['social'];
        self::assertTrue(null === $social || is_array($social));

        /** @var array{network: string, isLiked: bool, canLike: bool, isOwn: bool}|null $social */
        return $social;
    }

    private function likeRows(): int
    {
        $this->entityManager()->clear();

        return $this->entityManager()->getRepository(SocialLike::class)->count([]);
    }
}
