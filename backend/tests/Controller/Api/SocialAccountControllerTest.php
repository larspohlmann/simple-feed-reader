<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\PrivateFeedSyncChange;
use App\Entity\SocialAccount;
use App\Enum\PrivateFeedSyncReason;
use App\Enum\PrivateFeedSyncSkip;
use App\Enum\SocialFeedKind;
use App\Service\Social\AccountVerifier\AccountVerifierInterface;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\SocialCredentialsModel;
use App\Service\Social\Model\VerifiedAccountModel;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\StubAccountVerifier;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

final class SocialAccountControllerTest extends ApiTestCase
{
    /** Must match framework.rate_limiter.social_account.limit in rate_limiter.yaml. */
    private const int CONNECT_BUDGET = 10;

    private const string APP_PASSWORD = 'abcd-efgh-ijkl-mnop';
    private const string ALICE = 'alice.bsky.social';

    protected function setUp(): void
    {
        self::bootKernel();
        $rateLimiterCache = self::getContainer()->get('test.cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $rateLimiterCache);
        $rateLimiterCache->clear();
        self::ensureKernelShutdown();
    }

    public function testTheListRefusesAnAnonymousCaller(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/me/social-accounts');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testAnAccountWithoutConnectionsListsNothing(): void
    {
        $client = $this->clientVerifying($this->alice());
        $this->signIn($client, 'social-empty@example.test');

        $client->request('GET', '/api/me/social-accounts');

        self::assertResponseIsSuccessful();
        self::assertSame(['accounts' => []], $this->payload($client));
    }

    public function testConnectingBlueskyReturnsTheAccountWithoutTheSecret(): void
    {
        $client = $this->clientVerifying($this->alice());
        $this->signIn($client, 'social-bluesky@example.test');

        $this->connectBluesky($client);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $account = $this->payload($client);
        self::assertSame(
            ['id', 'network', 'handle', 'serviceUrl', 'status', 'isDefault', 'credentialHint', 'connectedAt', 'sync'],
            array_keys($account),
        );
        self::assertSame(['checkedAt' => null, 'skipped' => null, 'changes' => []], $account['sync']);
        self::assertSame('bluesky', $account['network']);
        self::assertSame(self::ALICE, $account['handle']);
        self::assertSame('https://bsky.social', $account['serviceUrl']);
        self::assertSame('connected', $account['status']);
        self::assertTrue($account['isDefault']);
        self::assertSame('mnop', $account['credentialHint']);
        self::assertIsString($account['connectedAt']);
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $account['connectedAt']));
        self::assertStringNotContainsString(self::APP_PASSWORD, (string) $client->getResponse()->getContent());
    }

    public function testTheBlueskyHandleReachesTheVerifierNormalised(): void
    {
        $stub = $this->alice();
        $client = $this->clientVerifying($stub);
        $this->signIn($client, 'social-handle@example.test');

        $this->connectBluesky($client, '@Alice.bsky.social');

        self::assertSame(self::ALICE, $stub->lastCredentials?->address);
    }

    public function testAMainPasswordIsRefusedBeforeAnyNetworkCall(): void
    {
        $stub = $this->alice();
        $client = $this->clientVerifying($stub);
        $this->signIn($client, 'social-main-password@example.test');

        $this->connectBluesky($client, self::ALICE, 'hunter2hunter2');

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('social_app_password_required', $this->payload($client)['type']);
        self::assertNull($stub->lastCredentials);
        self::assertStringNotContainsString('hunter2hunter2', (string) $client->getResponse()->getContent());
    }

    public function testAnAppPasswordTypedWithATrailingSpaceConnects(): void
    {
        $client = $this->clientVerifying($this->alice());
        $this->signIn($client, 'social-trailing-space@example.test');

        $this->connectBluesky($client, self::ALICE, self::APP_PASSWORD . ' ');

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    public function testReconnectingBlueskyWithAMainPasswordIsRefusedBeforeTheBudgetAndTheNetwork(): void
    {
        $stub = $this->alice();
        $client = $this->clientVerifying($stub);
        $this->signIn($client, 'social-reconnect-main-password@example.test');
        $id = $this->connectedId($client);
        $stub->lastCredentials = null;

        for ($attempt = 0; $attempt < self::CONNECT_BUDGET; ++$attempt) {
            $this->putJson(
                $client,
                sprintf('/api/me/social-accounts/%d/credential', $id),
                '{"secret":"hunter2hunter2"}',
            );
            $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
            self::assertSame('social_app_password_required', $this->payload($client)['type']);
        }
        self::assertNull($stub->lastCredentials);

        $this->replaceCredential($client, $id);
        self::assertResponseIsSuccessful();
    }

    public function testConnectingMastodonNormalisesTheInstance(): void
    {
        $stub = new StubAccountVerifier(
            new VerifiedAccountModel('42', 'alice@mastodon.example.test', 'https://mastodon.example.test'),
        );
        $client = $this->clientVerifying($stub);
        $this->signIn($client, 'social-mastodon@example.test');

        $this->connectMastodon($client, 'mastodon.example.test/');

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame('https://mastodon.example.test', $stub->lastCredentials?->address);
        self::assertSame('mastodon', $this->payload($client)['network']);
    }

    public function testAPlainHttpInstanceIsRefused(): void
    {
        $client = $this->clientVerifying($this->alice());
        $this->signIn($client, 'social-http@example.test');

        $this->connectMastodon($client, 'http://mastodon.example.test');

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('social_account_unresolvable', $this->payload($client)['type']);
    }

    public function testAnInvalidInstanceUrlSpendsNoConnectBudget(): void
    {
        $client = $this->clientVerifying(new StubAccountVerifier(
            new VerifiedAccountModel('42', 'alice@mastodon.example.test', 'https://mastodon.example.test'),
        ));
        $this->signIn($client, 'social-http-budget@example.test');

        for ($attempt = 0; $attempt < self::CONNECT_BUDGET; ++$attempt) {
            $this->connectMastodon($client, 'http://mastodon.example.test');
            $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->connectMastodon($client, 'https://mastodon.example.test');

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    public function testRejectedCredentialsAnswer422(): void
    {
        $client = $this->clientVerifying(new StubAccountVerifier(new SocialCredentialsRejectedException('Nope.')));
        $this->signIn($client, 'social-rejected@example.test');

        $this->connectBluesky($client);

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('social_credentials_rejected', $this->payload($client)['type']);
        self::assertStringNotContainsString(self::APP_PASSWORD, (string) $client->getResponse()->getContent());
    }

    public function testAnUnreachableNetworkAnswers502(): void
    {
        $client = $this->clientVerifying(new StubAccountVerifier(new SocialNetworkUnreachableException('Down.')));
        $this->signIn($client, 'social-unreachable@example.test');

        $this->connectBluesky($client);

        $this->assertRejected($client, Response::HTTP_BAD_GATEWAY);
        self::assertSame('social_network_unreachable', $this->payload($client)['type']);
    }

    public function testConnectingTheSameAccountTwiceAnswers409(): void
    {
        $client = $this->clientVerifying($this->alice());
        $this->signIn($client, 'social-twice@example.test');
        $this->connectBluesky($client);

        $this->connectBluesky($client);

        $this->assertRejected($client, Response::HTTP_CONFLICT);
        self::assertSame('social_account_already_connected', $this->payload($client)['type']);
    }

    public function testReconnectReplacesTheHint(): void
    {
        $client = $this->clientVerifying($this->alice());
        $this->signIn($client, 'social-reconnect@example.test');
        $id = $this->connectedId($client);

        $this->replaceCredential($client, $id);

        self::assertResponseIsSuccessful();
        $account = $this->payload($client);
        self::assertSame('9876', $account['credentialHint']);
        self::assertStringNotContainsString('wxyz-wxyz-wxyz-9876', (string) $client->getResponse()->getContent());
        self::assertSame('connected', $account['status']);
    }

    public function testAnotherUsersAccountAnswers404OnEveryIdRoute(): void
    {
        $client = $this->clientVerifying($this->alice());
        $this->signIn($client, 'social-owner@example.test');
        $id = $this->connectedId($client);
        $this->signIn($client, 'social-intruder@example.test');

        $this->replaceCredential($client, $id);
        $this->assertNotFound($client);
        $this->putJson($client, sprintf('/api/me/social-accounts/%d/default', $id), '');
        $this->assertNotFound($client);
        $client->request('DELETE', sprintf('/api/me/social-accounts/%d', $id));
        $this->assertNotFound($client);
    }

    public function testOwnershipIsCheckedBeforeTheBudget(): void
    {
        $client = $this->clientVerifying($this->echoingVerifier());
        $this->signIn($client, 'social-budget-owner@example.test');
        $ownerId = $this->connectedId($client);
        $this->signIn($client, 'social-budget-intruder@example.test');
        for ($attempt = 0; $attempt < self::CONNECT_BUDGET; ++$attempt) {
            $this->connectBluesky($client);
        }

        $this->replaceCredential($client, $ownerId);

        $this->assertNotFound($client);
    }

    public function testReconnectSpendsTheConnectBudget(): void
    {
        $client = $this->clientVerifying($this->alice());
        $this->signIn($client, 'social-reconnect-budget@example.test');
        $id = $this->connectedId($client);
        for ($attempt = 1; $attempt < self::CONNECT_BUDGET; ++$attempt) {
            $this->replaceCredential($client, $id);
            self::assertResponseIsSuccessful();
        }

        $this->replaceCredential($client, $id);

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
    }

    public function testMakeDefaultReturnsTheListWithTheFlagMoved(): void
    {
        $client = $this->clientVerifying($this->echoingVerifier());
        $this->signIn($client, 'social-default@example.test');
        $firstId = $this->connectedId($client);
        $this->connectBluesky($client, 'bob.bsky.social');
        $secondId = $this->payload($client)['id'];
        self::assertIsInt($secondId);
        self::assertFalse($this->payload($client)['isDefault']);

        $this->putJson($client, sprintf('/api/me/social-accounts/%d/default', $secondId), '');

        self::assertResponseIsSuccessful();
        self::assertSame(
            [$firstId => false, $secondId => true],
            $this->defaultFlags($client),
        );
    }

    public function testDisconnectReturnsTheListWithoutTheAccount(): void
    {
        $client = $this->clientVerifying($this->alice());
        $this->signIn($client, 'social-disconnect@example.test');
        $id = $this->connectedId($client);

        $client->request('DELETE', sprintf('/api/me/social-accounts/%d', $id));

        self::assertResponseIsSuccessful();
        self::assertSame(['accounts' => []], $this->payload($client));
    }

    public function testTheConnectBudgetIsTenPerQuarterHour(): void
    {
        $client = $this->clientVerifying(new StubAccountVerifier(new SocialCredentialsRejectedException('Nope.')));
        $this->signIn($client, 'social-budget@example.test');

        for ($attempt = 0; $attempt < self::CONNECT_BUDGET; ++$attempt) {
            $this->connectBluesky($client);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->connectBluesky($client);

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
    }

    public function testTheListShowsTheLastCheckAndTheRecentChangesNewestFirst(): void
    {
        $client = $this->clientVerifying($this->alice());
        $this->signIn($client, 'social-sync-list@example.test');
        $id = $this->connectedId($client);
        $this->stampSkippedCheck($id, '2026-10-17 11:00:00');
        $this->recordChange($id, PrivateFeedSyncReason::Saved, '2026-10-17 10:00:00');
        $this->recordChange($id, PrivateFeedSyncReason::Deleted, '2026-10-17 10:30:00');

        $client->request('GET', '/api/me/social-accounts');

        self::assertResponseIsSuccessful();
        $sync = $this->syncOfFirstListed($client);
        self::assertSame('2026-10-17T11:00:00+00:00', $sync['checkedAt']);
        self::assertSame('unreachable', $sync['skipped']);
        self::assertSame(
            [
                ['kind' => 'list', 'name' => 'News', 'reason' => 'deleted', 'at' => '2026-10-17T10:30:00+00:00'],
                ['kind' => 'list', 'name' => 'News', 'reason' => 'saved', 'at' => '2026-10-17T10:00:00+00:00'],
            ],
            $sync['changes'],
        );
    }

    public function testEachListedAccountCarriesOnlyItsOwnChanges(): void
    {
        $client = $this->clientVerifying($this->echoingVerifier());
        $this->signIn($client, 'social-sync-own@example.test');
        $firstId = $this->connectedId($client);
        $this->connectBluesky($client, 'bob.bsky.social');
        $secondId = $this->payload($client)['id'];
        self::assertIsInt($secondId);
        $this->recordChange($firstId, PrivateFeedSyncReason::Created, '2026-10-17 10:00:00');

        $client->request('GET', '/api/me/social-accounts');

        $changeCounts = [];
        foreach ($this->listedAccounts($client) as $account) {
            $changeCounts[$account['id']] = count($account['sync']['changes']);
        }
        self::assertSame([$firstId => 1, $secondId => 0], $changeCounts);
    }

    public function testAnotherUsersSyncChangesAreAbsentFromTheList(): void
    {
        $client = $this->clientVerifying($this->alice());
        $this->signIn($client, 'social-sync-owner@example.test');
        $ownerAccountId = $this->connectedId($client);
        $this->recordChange($ownerAccountId, PrivateFeedSyncReason::Created, '2026-10-17 10:00:00');
        $this->signIn($client, 'social-sync-other@example.test');
        $this->connectBluesky($client, 'bob.bsky.social');

        $client->request('GET', '/api/me/social-accounts');

        $accounts = $this->listedAccounts($client);
        self::assertCount(1, $accounts);
        self::assertSame([], $accounts[0]['sync']['changes']);
    }

    public function testAReconnectAnswersTheAccountWithItsRecentChanges(): void
    {
        $client = $this->clientVerifying($this->alice());
        $this->signIn($client, 'social-sync-reconnect@example.test');
        $id = $this->connectedId($client);
        $this->recordChange($id, PrivateFeedSyncReason::Unsaved, '2026-10-17 10:00:00');

        $this->replaceCredential($client, $id);

        self::assertResponseIsSuccessful();
        self::assertSame(['unsaved'], $this->changeReasonsOfAccount($client));
    }

    private function stampSkippedCheck(int $accountId, string $checkedAt): void
    {
        $entityManager = $this->entityManager();
        $account = $entityManager->find(SocialAccount::class, $accountId);
        self::assertInstanceOf(SocialAccount::class, $account);
        $account->recordSkippedSync(new \DateTimeImmutable($checkedAt), PrivateFeedSyncSkip::Unreachable);
        $entityManager->flush();
    }

    private function recordChange(int $accountId, PrivateFeedSyncReason $reason, string $changedAt): void
    {
        $entityManager = $this->entityManager();
        $account = $entityManager->find(SocialAccount::class, $accountId);
        self::assertInstanceOf(SocialAccount::class, $account);
        $entityManager->persist(new PrivateFeedSyncChange(
            $account,
            SocialFeedKind::UserList,
            'at://list/news',
            'News',
            $reason,
            new \DateTimeImmutable($changedAt),
        ));
        $entityManager->flush();
    }

    /** @return list<string> */
    private function changeReasonsOfAccount(KernelBrowser $client): array
    {
        /** @var array{sync: array{changes: list<array{reason: string}>}} $account */
        $account = $this->payload($client);

        return array_map(static fn (array $change): string => $change['reason'], $account['sync']['changes']);
    }

    /** @return array{checkedAt: string|null, skipped: string|null, changes: list<array<string, mixed>>} */
    private function syncOfFirstListed(KernelBrowser $client): array
    {
        return $this->listedAccounts($client)[0]['sync'];
    }

    /** @return list<array{id: int, sync: array{checkedAt: string|null, skipped: string|null, changes: list<array<string, mixed>>}}> */
    private function listedAccounts(KernelBrowser $client): array
    {
        /** @var list<array{id: int, sync: array{checkedAt: string|null, skipped: string|null, changes: list<array<string, mixed>>}}> $accounts */
        $accounts = $this->payload($client)['accounts'];

        return $accounts;
    }

    private function alice(): StubAccountVerifier
    {
        return new StubAccountVerifier(new VerifiedAccountModel('did:plc:alice', self::ALICE, 'https://bsky.social'));
    }

    private function echoingVerifier(): AccountVerifierInterface
    {
        return new class implements AccountVerifierInterface {
            public function verify(SocialCredentialsModel $credentials): VerifiedAccountModel
            {
                return new VerifiedAccountModel(
                    'did:plc:' . $credentials->address,
                    $credentials->address,
                    'https://bsky.social',
                );
            }
        };
    }

    private function clientVerifying(AccountVerifierInterface $stub): KernelBrowser
    {
        $client = static::createClient();
        // KernelBrowser rebuilds the container after every request, which would discard the stub.
        $client->disableReboot();
        self::getContainer()->set(AccountVerifierInterface::class, $stub);

        return $client;
    }

    private function connectBluesky(
        KernelBrowser $client,
        string $handle = self::ALICE,
        string $appPassword = self::APP_PASSWORD,
    ): void {
        $this->postJson(
            $client,
            '/api/me/social-accounts/bluesky',
            sprintf('{"handle":"%s","appPassword":"%s"}', $handle, $appPassword),
        );
    }

    private function connectMastodon(KernelBrowser $client, string $instanceUrl): void
    {
        $this->postJson(
            $client,
            '/api/me/social-accounts/mastodon',
            sprintf('{"instanceUrl":"%s","accessToken":"token-1234567890"}', $instanceUrl),
        );
    }

    private function connectedId(KernelBrowser $client): int
    {
        $this->connectBluesky($client);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $id = $this->payload($client)['id'];
        self::assertIsInt($id);

        return $id;
    }

    /** @return array<int, bool> */
    private function defaultFlags(KernelBrowser $client): array
    {
        $accounts = $this->payload($client)['accounts'];
        self::assertIsArray($accounts);
        $flags = [];
        foreach ($accounts as $account) {
            self::assertIsArray($account);
            self::assertIsInt($account['id']);
            self::assertIsBool($account['isDefault']);
            $flags[$account['id']] = $account['isDefault'];
        }

        return $flags;
    }

    private function assertNotFound(KernelBrowser $client): void
    {
        $this->assertRejected($client, Response::HTTP_NOT_FOUND);
        self::assertSame('social_account_not_found', $this->payload($client)['type']);
    }

    private function replaceCredential(KernelBrowser $client, int $id): void
    {
        $this->putJson(
            $client,
            sprintf('/api/me/social-accounts/%d/credential', $id),
            '{"secret":"wxyz-wxyz-wxyz-9876"}',
        );
    }

    private function putJson(KernelBrowser $client, string $uri, string $json): void
    {
        $client->request('PUT', $uri, server: ['CONTENT_TYPE' => 'application/json'], content: $json);
    }

    private function postJson(KernelBrowser $client, string $uri, string $json): void
    {
        $client->request('POST', $uri, server: ['CONTENT_TYPE' => 'application/json'], content: $json);
    }
}
