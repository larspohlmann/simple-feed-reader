<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\SocialAccount;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Service\Crypto\InstanceSecretCipher;
use App\Service\Social\Crypto\SocialCredentialCipher;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\NetworkFeedSubscriptions;
use App\Tests\Support\RoutesSocialApis;
use App\Tests\Support\SocialAccounts;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class EntrySocialCountsControllerTest extends ApiTestCase
{
    use RoutesSocialApis;

    private const array ROUTED_HOSTS = ['mastodon.social', 'mastodon.example'];
    private const string ORIGIN = 'GET https://mastodon.social/api/v1/statuses/110';
    private const string SEARCH = 'GET https://mastodon.example/api/v2/search?q='
        . 'https%3A%2F%2Fmastodon.social%2F%40Gargron%2F110&resolve=true&type=statuses&limit=1';

    /** Must match framework.rate_limiter.social_counts.limit in rate_limiter.yaml. */
    private const int COUNTS_BUDGET = 120;

    protected function setUp(): void
    {
        self::bootKernel();
        foreach (['test.cache.rate_limiter', 'social.counts.cache'] as $poolId) {
            $pool = self::getContainer()->get($poolId);
            self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
            $pool->clear();
        }
        self::ensureKernelShutdown();
    }

    public function testAnAnonymousCallerIsRefused(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/entries/1/social');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testAPostsCountsComeFromItsOriginWithoutAnAccount(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [self::ORIGIN => self::originStatus()]);
        $user = $this->signIn($client, 'counts-origin@example.test');

        $client->request('GET', sprintf('/api/entries/%d/social', $this->mastodonPostFor($user)));

        self::assertResponseIsSuccessful();
        self::assertSame(['likes' => 5, 'reposts' => 2, 'replies' => 1, 'quotes' => null], $this->payload($client));
        self::assertSame([self::ORIGIN], $this->http->requestLines());
    }

    public function testASecondOpenIsAnsweredFromTheCache(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [self::ORIGIN => self::originStatus()]);
        $path = sprintf('/api/entries/%d/social', $this->mastodonPostFor(
            $this->signIn($client, 'counts-cached@example.test'),
        ));

        $client->request('GET', $path);
        $client->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertSame(5, $this->payload($client)['likes']);
        self::assertSame([self::ORIGIN], $this->http->requestLines());
    }

    public function testRefreshAsksTheOriginAgain(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [self::ORIGIN => self::originStatus()]);
        $path = sprintf('/api/entries/%d/social', $this->mastodonPostFor(
            $this->signIn($client, 'counts-refresh@example.test'),
        ));

        $client->request('GET', $path);
        $client->request('GET', $path . '?refresh=1');

        self::assertResponseIsSuccessful();
        self::assertSame(5, $this->payload($client)['likes']);
        self::assertSame([self::ORIGIN, self::ORIGIN], $this->http->requestLines());
    }

    public function testAnOriginClosedToThePublicFallsBackToTheUsersAccount(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::ORIGIN => new MockResponse('{}', ['http_code' => Response::HTTP_UNAUTHORIZED]),
            self::SEARCH => new MockResponse(json_encode(['statuses' => [[
                'id' => '110',
                'favourites_count' => 9,
                'reblogs_count' => 0,
                'replies_count' => 3,
            ]]], \JSON_THROW_ON_ERROR)),
        ]);
        $user = $this->signIn($client, 'counts-fallback@example.test');
        $this->mastodonAccountOf($user);

        $client->request('GET', sprintf('/api/entries/%d/social', $this->mastodonPostFor($user)));

        self::assertResponseIsSuccessful();
        self::assertSame(['likes' => 9, 'reposts' => 0, 'replies' => 3, 'quotes' => null], $this->payload($client));
        self::assertSame([self::ORIGIN, self::SEARCH], $this->http->requestLines());
    }

    public function testAFailingOriginWithoutAnAccountIsAnUnreachableNetwork(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::ORIGIN => new MockResponse('{}', ['http_code' => Response::HTTP_INTERNAL_SERVER_ERROR]),
        ]);
        $user = $this->signIn($client, 'counts-unreachable@example.test');

        $client->request('GET', sprintf('/api/entries/%d/social', $this->mastodonPostFor($user)));

        $this->assertRejected($client, Response::HTTP_BAD_GATEWAY);
        self::assertSame('social_network_unreachable', $this->payload($client)['type']);
    }

    public function testAThrottlingOriginWithoutAnAccountSaysWhenToAskAgain(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [self::ORIGIN => new MockResponse('{}', [
            'http_code' => Response::HTTP_TOO_MANY_REQUESTS,
            'response_headers' => ['Retry-After: 30'],
        ])]);
        $user = $this->signIn($client, 'counts-throttled@example.test');

        $client->request('GET', sprintf('/api/entries/%d/social', $this->mastodonPostFor($user)));

        $this->assertRejected($client, Response::HTTP_SERVICE_UNAVAILABLE);
        self::assertSame('social_network_throttled', $this->payload($client)['type']);
        self::assertSame('30', $client->getResponse()->headers->get('Retry-After'));
    }

    public function testAnOrdinaryArticleHasNoCounts(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $user = $this->signIn($client, 'counts-plain@example.test');

        $client->request('GET', sprintf('/api/entries/%d/social', $this->plainArticleFor($user)));

        $this->assertRejected($client, Response::HTTP_CONFLICT);
        self::assertSame('social_post_unsupported', $this->payload($client)['type']);
        self::assertSame([], $this->http->requests);
    }

    public function testAnotherUsersEntryReadsAsMissing(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $entryId = $this->mastodonPostFor($this->factory()->create('counts-stranger@example.test'));
        $this->signIn($client, 'counts-own@example.test');

        $client->request('GET', sprintf('/api/entries/%d/social', $entryId));

        $this->assertRejected($client, Response::HTTP_NOT_FOUND);
        self::assertSame([], $this->http->requests);
    }

    public function testTheCountsHaveTheirOwnBudget(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $path = sprintf('/api/entries/%d/social', $this->plainArticleFor(
            $this->signIn($client, 'counts-budget@example.test'),
        ));
        for ($attempt = 0; $attempt < self::COUNTS_BUDGET; ++$attempt) {
            $client->request('GET', $path);
            self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        }

        $client->request('GET', $path);

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
    }

    private function signIn(KernelBrowser $client, string $email): User
    {
        $user = $this->factory()->create($email);
        /** @var JWTTokenManagerInterface $tokens */
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $tokens->create($user));

        return $user;
    }

    private function mastodonAccountOf(User $user): void
    {
        /** @var InstanceSecretCipher $instanceCipher */
        $instanceCipher = self::getContainer()->get(InstanceSecretCipher::class);
        $this->entityManager()->persist(new SocialAccount(
            $user,
            SocialNetwork::Mastodon,
            'alice@mastodon.example',
            '@alice@mastodon.example',
            'https://mastodon.example',
            (new SocialCredentialCipher($instanceCipher))->seal($user->requireId(), SocialAccounts::ACCESS_TOKEN),
            '7890',
            new \DateTimeImmutable('2026-10-12 10:00:00'),
        ));
        $this->entityManager()->flush();
    }

    private static function originStatus(): MockResponse
    {
        return new MockResponse(json_encode([
            'id' => '110',
            'uri' => 'https://mastodon.social/users/Gargron/statuses/110',
            'url' => self::MASTODON_POST_URL,
            'favourites_count' => 5,
            'reblogs_count' => 2,
            'replies_count' => 1,
        ], \JSON_THROW_ON_ERROR));
    }

    private function plainArticleFor(User $user): int
    {
        $fixtures = new NetworkFeedSubscriptions($this->entityManager());
        $subscription = $fixtures->subscribe($user, NetworkFeedSubscriptions::PLAIN);

        return $fixtures->post($subscription, self::MASTODON_POST_URL, '2026-10-12T10:00:00Z')->requireId();
    }
}
