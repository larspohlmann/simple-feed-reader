<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\SocialLike;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\RoutesSocialApis;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class EntrySocialViewerControllerTest extends ApiTestCase
{
    use RoutesSocialApis;

    private const array ROUTED_HOSTS = ['mastodon.example'];
    private const string SEARCH = 'GET https://mastodon.example/api/v2/search?q='
        . 'https%3A%2F%2Fmastodon.social%2F%40Gargron%2F110&resolve=true&type=statuses&limit=1';
    private const string FAVOURITE = 'POST https://mastodon.example/api/v1/statuses/110/favourite';

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

    private function viewerPath(int $entryId): string
    {
        return sprintf('/api/entries/%d/social/viewer', $entryId);
    }

    /** @param array<string, mixed> $fields */
    private static function foundStatus(array $fields): MockResponse
    {
        return new MockResponse(json_encode(['statuses' => [['id' => '110', ...$fields]]], \JSON_THROW_ON_ERROR));
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
