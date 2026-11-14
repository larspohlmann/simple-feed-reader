<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\DiscussionVote;
use App\Entity\Entry;
use App\Entity\SocialAccount;
use App\Entity\User;
use App\Enum\SocialAccountStatus;
use App\Enum\VoteScore;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\NetworkFeedSubscriptions;
use App\Service\Outbound\Fetch\DnsResolver\NativeDnsResolver;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\RoutesSocialApis;
use App\Tests\Support\StaticDnsResolver;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class EntryVoteControllerTest extends ApiTestCase
{
    use RoutesSocialApis;

    private const array ROUTED_HOSTS = ['lemmy.example', 'feddit.example'];
    private const string FIXTURES = __DIR__ . '/../../Fixtures/lemmy/';
    private const string HOME_POST = 'GET https://lemmy.example/api/v3/post?id=42';
    private const string FEDERATED_POST = 'GET https://feddit.example/api/v3/post?id=7';
    private const string RESOLVE = 'GET https://lemmy.example/api/v3/resolve_object?q='
        . 'https%3A%2F%2Forigin.example%2Fpost%2F3';
    private const string LIKE = 'POST https://lemmy.example/api/v3/post/like';

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

        $client->request('PUT', '/api/entries/1/vote');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testAnUpvoteReachesTheHomeInstanceAndIsStored(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::HOME_POST => self::fixture('post-as-account.json'),
            self::LIKE => self::fixture('post-like.json'),
        ]);
        $user = $this->signIn($client, 'vote-up@example.test');
        $this->lemmyAccountOf($user);
        $entryId = $this->lemmyPostFor($user);

        $this->vote($client, $entryId, 1);

        self::assertResponseIsSuccessful();
        self::assertSame(['myVote' => 1, 'score' => 43], $this->payload($client));
        self::assertSame([self::HOME_POST, self::LIKE], $this->routesAsked());
        self::assertSame('{"post_id":42,"score":1}', $this->http->requests[1]['body']);
        self::assertSame([VoteScore::Up], $this->storedScores());
    }

    public function testAVoteOnAFederatedCopyActsOnTheResolvedPost(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::FEDERATED_POST => self::fixture('post-anonymous.json'),
            self::RESOLVE => self::fixture('resolve-post.json'),
            self::LIKE => self::fixture('post-like.json'),
        ]);
        $user = $this->signIn($client, 'vote-federated@example.test');
        $this->lemmyAccountOf($user);
        $entryId = $this->lemmyPostFor($user, 'https://feddit.example/post/7');

        $this->vote($client, $entryId, 1);

        self::assertResponseIsSuccessful();
        self::assertSame('{"post_id":990,"score":1}', $this->http->requests[2]['body']);
    }

    public function testClearingAVoteDropsTheStoredRow(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::HOME_POST => self::fixture('post-as-account.json'),
            self::LIKE => self::fixture('post-like-no-vote.json'),
        ]);
        $user = $this->signIn($client, 'vote-clear@example.test');
        $account = $this->lemmyAccountOf($user);
        $entryId = $this->lemmyPostFor($user);
        $this->storeVote($user, $entryId, $account, VoteScore::Up);

        $this->vote($client, $entryId, 0);

        self::assertResponseIsSuccessful();
        self::assertSame(['myVote' => 0, 'score' => 43], $this->payload($client));
        self::assertSame([], $this->storedScores());
    }

    public function testARefusedDownvoteKeepsTheStoredVote(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::HOME_POST => self::fixture('post-as-account.json'),
            self::LIKE => new MockResponse(
                '{"error":"downvotes_are_disabled"}',
                ['http_code' => Response::HTTP_BAD_REQUEST],
            ),
        ]);
        $user = $this->signIn($client, 'vote-refused@example.test');
        $account = $this->lemmyAccountOf($user);
        $entryId = $this->lemmyPostFor($user);
        $this->storeVote($user, $entryId, $account, VoteScore::Up);

        $this->vote($client, $entryId, -1);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        $problem = $this->payload($client);
        self::assertSame('social_post_refused', $problem['type']);
        self::assertSame('This Lemmy instance does not allow downvotes.', $problem['detail']);
        self::assertSame([VoteScore::Up], $this->storedScores());
    }

    public function testARefusedSessionMarksTheAccountForReconnect(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::HOME_POST => new MockResponse('{}', ['http_code' => Response::HTTP_UNAUTHORIZED]),
        ]);
        $user = $this->signIn($client, 'vote-session@example.test');
        $accountId = $this->lemmyAccountOf($user)->requireId();
        $entryId = $this->lemmyPostFor($user);

        $this->vote($client, $entryId, 1);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('social_credentials_rejected', $this->payload($client)['type']);
        $this->entityManager()->clear();
        self::assertSame(
            SocialAccountStatus::NeedsReconnect,
            $this->entityManager()->find(SocialAccount::class, $accountId)?->getStatus(),
        );
        $asked = count($this->http->requests);

        $this->vote($client, $entryId, 1);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('social_account_needs_reconnect', $this->payload($client)['type']);
        self::assertCount($asked, $this->http->requests);
    }

    public function testAnEntryThatIsNoLemmyPostIsRefused(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $user = $this->signIn($client, 'vote-plain@example.test');
        $this->lemmyAccountOf($user);
        $fixtures = new NetworkFeedSubscriptions($this->entityManager());
        $entryId = $fixtures->post(
            $fixtures->subscribe($user, NetworkFeedSubscriptions::PLAIN),
            'https://example.com/plain',
            '2026-10-12T10:00:00Z',
        )->requireId();

        $this->vote($client, $entryId, 1);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('social_post_unsupported', $this->payload($client)['type']);
        self::assertSame('This entry is not a Lemmy post.', $this->payload($client)['detail']);
    }

    public function testWithoutALemmyAccountTheVoteIsRefused(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $user = $this->signIn($client, 'vote-no-account@example.test');

        $this->vote($client, $this->lemmyPostFor($user), 1);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('social_account_missing', $this->payload($client)['type']);
    }

    public function testAScoreOutsideTheThreeIsRefused(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $user = $this->signIn($client, 'vote-range@example.test');
        $this->lemmyAccountOf($user);

        $this->vote($client, $this->lemmyPostFor($user), 2);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame([], $this->http->requests);
    }

    public function testAnotherUsersEntryReadsAsMissing(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $stranger = $this->factory()->create('vote-stranger@example.test');
        $entryId = $this->lemmyPostFor($stranger);
        $user = $this->signIn($client, 'vote-own@example.test');
        $this->lemmyAccountOf($user);

        $this->vote($client, $entryId, 1);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame([], $this->http->requests);
    }

    public function testReadingTheVoteMirrorsTheNetwork(): void
    {
        $answers = ['post-like-downvoted.json', 'post-like-no-vote.json'];
        $this->http = RoutedHttpClient::answering(
            static function (string $method, string $url) use (&$answers): MockResponse {
                self::assertSame(self::HOME_POST, $method . ' ' . $url);

                return self::fixture((string) array_shift($answers));
            },
        );
        $client = static::createClient();
        $client->disableReboot();
        $container = self::getContainer();
        $container->set('http_client.uri_template', $this->http->client());
        $container->set(NativeDnsResolver::class, new StaticDnsResolver(['lemmy.example' => ['93.184.216.34']]));
        $user = $this->signIn($client, 'vote-read@example.test');
        $this->lemmyAccountOf($user);
        $entryId = $this->lemmyPostFor($user);

        $client->request('GET', sprintf('/api/entries/%d/vote', $entryId));

        self::assertResponseIsSuccessful();
        self::assertSame(['myVote' => -1], $this->payload($client));
        self::assertSame([VoteScore::Down], $this->storedScores());

        $client->request('GET', sprintf('/api/entries/%d/vote', $entryId));

        self::assertSame(['myVote' => 0], $this->payload($client));
        self::assertSame([], $this->storedScores());
    }

    public function testVotesDrawOnTheLikeBudget(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $user = $this->signIn($client, 'vote-budget@example.test');
        $this->lemmyAccountOf($user);
        $fixtures = new NetworkFeedSubscriptions($this->entityManager());
        $entryId = $fixtures->post(
            $fixtures->subscribe($user, NetworkFeedSubscriptions::PLAIN),
            'https://example.com/plain',
            '2026-10-12T10:00:00Z',
        )->requireId();
        for ($attempt = 0; $attempt < self::LIKE_BUDGET; ++$attempt) {
            $this->vote($client, $entryId, 1);
        }

        $this->vote($client, $entryId, 1);

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
    }

    private function vote(KernelBrowser $client, int $entryId, int $score): void
    {
        $client->jsonRequest('PUT', sprintf('/api/entries/%d/vote', $entryId), ['score' => $score]);
    }

    private static function fixture(string $name): MockResponse
    {
        return new MockResponse((string) file_get_contents(self::FIXTURES . $name));
    }

    private function storeVote(User $user, int $entryId, SocialAccount $account, VoteScore $score): void
    {
        $entry = $this->entityManager()->find(Entry::class, $entryId);
        self::assertInstanceOf(Entry::class, $entry);
        $this->entityManager()->persist(
            new DiscussionVote($user, $entry, $account, $score, new \DateTimeImmutable('2026-10-12 10:00:00')),
        );
        $this->entityManager()->flush();
    }

    /** @return list<VoteScore> */
    private function storedScores(): array
    {
        $this->entityManager()->clear();

        return array_map(
            static fn (DiscussionVote $vote): VoteScore => $vote->getScore(),
            $this->entityManager()->getRepository(DiscussionVote::class)->findAll(),
        );
    }
}
