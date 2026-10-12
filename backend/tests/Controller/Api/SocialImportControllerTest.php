<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\SocialAccount;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\FollowedAccountModel;
use App\Service\Social\Model\NetworkCandidatesModel;
use App\Service\Social\Model\SocialCollectionModel;
use App\Service\Social\SocialCandidates\SocialCandidatesInterface;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\ProfileFeedSubscriptions;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StubSocialCandidates;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

final class SocialImportControllerTest extends ApiTestCase
{
    /** Must match framework.rate_limiter.social_import.limit in rate_limiter.yaml. */
    private const int IMPORT_BUDGET = 10;

    protected function setUp(): void
    {
        self::bootKernel();
        $rateLimiterCache = self::getContainer()->get('test.cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $rateLimiterCache);
        $rateLimiterCache->clear();
        self::ensureKernelShutdown();
    }

    public function testTheCandidatesRefuseAnAnonymousCaller(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/me/social-accounts/1/import-candidates');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testTheCandidatesListFollowsByStateThenHandleBesideListsAndFeeds(): void
    {
        $client = $this->clientWith(new StubSocialCandidates(new NetworkCandidatesModel(
            [
                self::follow('did:plc:zed', '@zed.bsky.social'),
                self::follow('did:plc:bob', '@bob.bsky.social'),
                self::follow('did:plc:amy', '@amy.bsky.social'),
            ],
            [new SocialCollectionModel('at://list/b', 'Writers'), new SocialCollectionModel('at://list/a', 'Artists')],
            [new SocialCollectionModel('at://feed/a', 'Discover')],
            false,
        )));
        $user = $this->signIn($client, 'import-candidates-shape@example.test');
        $account = $this->blueskyAccountOf($user);
        $bob = $this->subscribe($user, 'https://bsky.app/profile/did:plc:bob/rss');
        $gone = $this->subscribe($user, 'https://bsky.app/profile/did:plc:gone/rss', 'Gone Account');
        $this->subscribe($user, 'https://example.com/feed.xml');

        $this->requestCandidates($client, $account);

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client);
        self::assertSame([
            self::row('did:plc:amy', '@amy.bsky.social', []),
            self::row('did:plc:zed', '@zed.bsky.social', []),
            self::row('did:plc:bob', '@bob.bsky.social', [
                'state' => 'subscribed',
                'subscriptionId' => $bob->requireId(),
            ]),
            self::row('did:plc:gone', 'did:plc:gone', [
                'displayName' => 'Gone Account',
                'state' => 'no_longer_followed',
                'subscriptionId' => $gone->requireId(),
            ]),
        ], $payload['follows']);
        self::assertSame(
            [['id' => 'at://list/a', 'name' => 'Artists'], ['id' => 'at://list/b', 'name' => 'Writers']],
            $payload['lists'],
        );
        self::assertSame([['id' => 'at://feed/a', 'name' => 'Discover']], $payload['customFeeds']);
        self::assertFalse($payload['truncated']);
    }

    public function testAnotherUsersAccountAnswers404BeforeTheBudget(): void
    {
        $client = $this->clientWith($this->nothingFollowed());
        $owner = $this->signIn($client, 'import-owner@example.test');
        $account = $this->blueskyAccountOf($owner);
        $intruder = $this->signIn($client, 'import-intruder@example.test');
        $ownAccount = $this->blueskyAccountOf($intruder);
        for ($attempt = 0; $attempt < self::IMPORT_BUDGET; ++$attempt) {
            $this->requestCandidates($client, $ownAccount);
        }

        $this->requestCandidates($client, $account);

        $this->assertRejected($client, Response::HTTP_NOT_FOUND);
        self::assertSame('social_account_not_found', $this->payload($client)['type']);
    }

    public function testAnAccountThatNeedsReconnectingIsRefusedBeforeTheBudgetAndTheNetwork(): void
    {
        $stub = $this->nothingFollowed();
        $client = $this->clientWith($stub);
        $user = $this->signIn($client, 'import-needs-reconnect@example.test');
        $account = $this->blueskyAccountOf($user);
        $account->markNeedsReconnect();
        $this->entityManager()->flush();

        for ($attempt = 0; $attempt <= self::IMPORT_BUDGET; ++$attempt) {
            $this->requestCandidates($client, $account);
            $this->assertRejected($client, Response::HTTP_CONFLICT);
            self::assertSame('social_account_needs_reconnect', $this->payload($client)['type']);
        }
        self::assertSame(0, $stub->calls);
    }

    public function testARefusedCredentialMarksTheAccountForReconnecting(): void
    {
        $client = $this->clientWith(new StubSocialCandidates(new SocialCredentialsRejectedException('Refused.')));
        $user = $this->signIn($client, 'import-refused@example.test');
        $account = $this->blueskyAccountOf($user);

        $this->requestCandidates($client, $account);

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('social_credentials_rejected', $this->payload($client)['type']);
        $client->request('GET', '/api/me/social-accounts');
        $accounts = $this->payload($client)['accounts'];
        self::assertIsArray($accounts);
        self::assertIsArray($accounts[0]);
        self::assertSame('needs_reconnect', $accounts[0]['status']);
    }

    public function testAnUnreachableNetworkAnswers502(): void
    {
        $client = $this->clientWith(new StubSocialCandidates(new SocialNetworkUnreachableException('Down.')));
        $user = $this->signIn($client, 'import-unreachable@example.test');

        $this->requestCandidates($client, $this->blueskyAccountOf($user));

        $this->assertRejected($client, Response::HTTP_BAD_GATEWAY);
        self::assertSame('social_network_unreachable', $this->payload($client)['type']);
    }

    public function testTheCandidatesBudgetIsTenPerQuarterHour(): void
    {
        $client = $this->clientWith($this->nothingFollowed());
        $user = $this->signIn($client, 'import-budget@example.test');
        $account = $this->blueskyAccountOf($user);
        for ($attempt = 0; $attempt < self::IMPORT_BUDGET; ++$attempt) {
            $this->requestCandidates($client, $account);
            self::assertResponseIsSuccessful();
        }

        $this->requestCandidates($client, $account);

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function row(string $identity, string $handle, array $overrides): array
    {
        return array_merge([
            'identity' => $identity,
            'handle' => $handle,
            'displayName' => null,
            'feedUrl' => 'https://bsky.app/profile/' . $identity . '/rss',
            'state' => 'new',
            'subscriptionId' => null,
        ], $overrides);
    }

    private static function follow(string $did, string $handle): FollowedAccountModel
    {
        return new FollowedAccountModel($did, $handle, null, 'https://bsky.app/profile/' . $did . '/rss');
    }

    private function nothingFollowed(): StubSocialCandidates
    {
        return new StubSocialCandidates(new NetworkCandidatesModel([], [], [], false));
    }

    private function clientWith(SocialCandidatesInterface $candidates): KernelBrowser
    {
        $client = static::createClient();
        // KernelBrowser rebuilds the container after every request, which would discard the stub.
        $client->disableReboot();
        self::getContainer()->set(SocialCandidatesInterface::class, $candidates);

        return $client;
    }

    private function signIn(KernelBrowser $client, string $email): User
    {
        $user = $this->factory()->create($email);
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $tokens->create($user));

        return $user;
    }

    private function blueskyAccountOf(User $user): SocialAccount
    {
        $account = SocialAccounts::blueskyOf($user);
        $this->entityManager()->persist($account);
        $this->entityManager()->flush();

        return $account;
    }

    private function subscribe(User $user, string $url, ?string $title = null): Subscription
    {
        return (new ProfileFeedSubscriptions($this->entityManager()))->subscribe($user, $url, $title);
    }

    private function requestCandidates(KernelBrowser $client, SocialAccount $account): void
    {
        $client->request('GET', sprintf('/api/me/social-accounts/%d/import-candidates', $account->requireId()));
    }
}
