<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
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
    private const string BOB_FEED = 'https://bsky.app/profile/did:plc:bob/rss';

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
            [
                self::collection('home', null, 'Home'),
                self::collection('list', 'at://list/a', 'Artists'),
                self::collection('list', 'at://list/b', 'Writers'),
            ],
            $payload['lists'],
        );
        self::assertSame([self::collection('custom_feed', 'at://feed/a', 'Discover')], $payload['customFeeds']);
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

    public function testAnImportCannotSetTheImageOfAFeed(): void
    {
        $client = $this->clientWith($this->nothingFollowed());
        $user = $this->signIn($client, 'import-no-client-image@example.test');

        $this->postImport($client, $this->blueskyAccountOf($user), [
            'subscribe' => [[
                'identity' => 'did:plc:bob',
                'title' => 'Bob',
                'avatarUrl' => 'https://tracker.example/pixel.gif',
                'imageUrl' => 'https://tracker.example/pixel.gif',
            ]],
            'collections' => [[
                'kind' => 'list',
                'id' => 'at://did:plc:bob/app.bsky.graph.list/3l1',
                'name' => 'Writers',
                'avatarUrl' => 'https://tracker.example/pixel.gif',
                'imageUrl' => 'https://tracker.example/pixel.gif',
            ]],
        ]);

        self::assertResponseIsSuccessful();
        $this->entityManager()->clear();
        $profileFeed = $this->entityManager()->getRepository(Feed::class)->findOneBy(['url' => self::BOB_FEED]);
        $listFeed = $this->entityManager()->getRepository(SocialFeed::class)->findOneBy([]);
        self::assertNotNull($profileFeed);
        self::assertNotNull($listFeed);
        self::assertNull($profileFeed->getImageUrl());
        self::assertNull($listFeed->getFeed()->getImageUrl());
    }

    public function testImportSubscribesTheChosenFollowsAndRemovesTheChosenSubscriptions(): void
    {
        $client = $this->clientWith($this->nothingFollowed());
        $user = $this->signIn($client, 'import-post@example.test');
        $account = $this->blueskyAccountOf($user);
        $gone = $this->subscribe($user, 'https://bsky.app/profile/did:plc:gone/rss');

        $this->postImport($client, $account, [
            'subscribe' => [['identity' => 'did:plc:bob', 'title' => 'Bob']],
            'unsubscribe' => [$gone->requireId()],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['imported' => 1, 'alreadySubscribed' => 0, 'invalid' => 0, 'skippedOverLimit' => 0, 'removed' => 1],
            $this->payload($client),
        );
    }

    public function testImportAcceptsAFollowWithoutADisplayName(): void
    {
        $client = $this->clientWith($this->nothingFollowed());
        $user = $this->signIn($client, 'import-null-title@example.test');

        $this->postImport($client, $this->blueskyAccountOf($user), [
            'subscribe' => [['identity' => 'did:plc:bob', 'title' => null]],
            'unsubscribe' => [],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->payload($client)['imported']);
    }

    public function testImportRefusesAFeedOfAnotherNetworkAndWritesNothing(): void
    {
        $client = $this->clientWith($this->nothingFollowed());
        $user = $this->signIn($client, 'import-foreign-feed@example.test');
        $gone = $this->subscribe($user, 'https://bsky.app/profile/did:plc:gone/rss');
        $goneId = $gone->requireId();

        $this->postImport($client, $this->blueskyAccountOf($user), [
            'subscribe' => [
                ['identity' => 'did:plc:bob', 'title' => 'Bob'],
                ['identity' => 'bob@mastodon.example', 'title' => 'Bob'],
            ],
            'unsubscribe' => [$goneId],
        ]);

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('social_import_invalid_feed', $this->payload($client)['type']);
        $this->entityManager()->clear();
        self::assertNotNull($this->entityManager()->find(Subscription::class, $goneId));
        self::assertNull($this->entityManager()->getRepository(Feed::class)->findOneBy([
            'url' => 'https://bsky.app/profile/did:plc:bob/rss',
        ]));
    }

    public function testImportWithNothingChosenIsAValidationError(): void
    {
        $client = $this->clientWith($this->nothingFollowed());
        $user = $this->signIn($client, 'import-empty@example.test');

        $this->postImport($client, $this->blueskyAccountOf($user), ['subscribe' => [], 'unsubscribe' => []]);

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('validation_error', $this->payload($client)['type']);
    }

    public function testImportCannotRemoveAnotherUsersSubscription(): void
    {
        $client = $this->clientWith($this->nothingFollowed());
        $stranger = $this->signIn($client, 'import-stranger@example.test');
        $theirs = $this->subscribe($stranger, 'https://bsky.app/profile/did:plc:theirs/rss');
        $user = $this->signIn($client, 'import-thief@example.test');

        $this->postImport($client, $this->blueskyAccountOf($user), [
            'subscribe' => [],
            'unsubscribe' => [$theirs->requireId()],
        ]);

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->entityManager()->clear();
        self::assertNotNull($this->entityManager()->find(Subscription::class, $theirs->requireId()));
    }

    public function testImportOnAnotherUsersAccountAnswers404(): void
    {
        $client = $this->clientWith($this->nothingFollowed());
        $owner = $this->signIn($client, 'import-post-owner@example.test');
        $account = $this->blueskyAccountOf($owner);
        $this->signIn($client, 'import-post-intruder@example.test');

        $this->postImport($client, $account, [
            'subscribe' => [['identity' => 'did:plc:bob', 'title' => 'Bob']],
        ]);

        $this->assertRejected($client, Response::HTTP_NOT_FOUND);
    }

    public function testImportOfCollectionsOnAnotherUsersAccountAnswers404AndAddsNothing(): void
    {
        $client = $this->clientWith($this->nothingFollowed());
        $owner = $this->signIn($client, 'import-collections-owner@example.test');
        $account = $this->blueskyAccountOf($owner);
        $this->signIn($client, 'import-collections-intruder@example.test');

        $this->postImport($client, $account, ['collections' => [
            ['kind' => 'home', 'id' => null, 'name' => 'Home'],
            ['kind' => 'list', 'id' => 'at://did:plc:bob/app.bsky.graph.list/3l1', 'name' => 'Writers'],
        ]]);

        $this->assertRejected($client, Response::HTTP_NOT_FOUND);
        self::assertSame('social_account_not_found', $this->payload($client)['type']);
        self::assertSame(0, $this->entityManager()->getRepository(SocialFeed::class)->count());
    }

    public function testImportSpendsNoCandidatesBudget(): void
    {
        $client = $this->clientWith($this->nothingFollowed());
        $user = $this->signIn($client, 'import-no-budget@example.test');
        $account = $this->blueskyAccountOf($user);
        for ($attempt = 0; $attempt <= self::IMPORT_BUDGET; ++$attempt) {
            $this->requestCandidates($client, $account);
        }
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);

        $this->postImport($client, $account, [
            'subscribe' => [['identity' => 'did:plc:bob', 'title' => 'Bob']],
        ]);

        self::assertResponseIsSuccessful();
    }

    public function testImportAddsTheChosenCollectionsAsPrivateFeeds(): void
    {
        $client = $this->clientWith($this->nothingFollowed());
        $user = $this->signIn($client, 'import-collections@example.test');
        $account = $this->blueskyAccountOf($user);

        $this->postImport($client, $account, ['collections' => [
            ['kind' => 'home', 'id' => null, 'name' => 'Home'],
            ['kind' => 'list', 'id' => 'at://did:plc:bob/app.bsky.graph.list/3l1', 'name' => 'Writers'],
        ]]);

        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->payload($client)['imported']);
        self::assertSame(2, $this->entityManager()->getRepository(SocialFeed::class)->count());
    }

    public function testImportTakesHomeAsHomeWhateverIdAndNameItCarries(): void
    {
        $client = $this->clientWith($this->nothingFollowed());
        $user = $this->signIn($client, 'import-home-with-id@example.test');
        $account = $this->blueskyAccountOf($user);

        $this->postImport($client, $account, ['collections' => [
            ['kind' => 'home', 'id' => 'at://did:plc:bob/app.bsky.graph.list/3l1', 'name' => 'Mine'],
        ]]);

        self::assertResponseIsSuccessful();
        $feed = $this->entityManager()->getRepository(Feed::class)->findOneBy([
            'url' => sprintf('social:bluesky:%d:home', $account->requireId()),
        ]);
        self::assertSame('Home', $feed?->getTitle());
        self::assertSame(1, $this->entityManager()->getRepository(SocialFeed::class)->count());
    }

    public function testImportRefusesACollectionOfTheWrongShape(): void
    {
        $client = $this->clientWith($this->nothingFollowed());
        $user = $this->signIn($client, 'import-bad-collection@example.test');

        $this->postImport($client, $this->blueskyAccountOf($user), ['collections' => [
            ['kind' => 'custom_feed', 'id' => '42', 'name' => 'Not a feed'],
        ]]);

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('social_import_invalid_feed', $this->payload($client)['type']);
    }

    public function testImportRefusesACustomFeedOnMastodon(): void
    {
        $client = $this->clientWith($this->nothingFollowed());
        $user = $this->signIn($client, 'import-mastodon-feed@example.test');

        $this->postImport($client, $this->stored(SocialAccounts::mastodonOf($user)), ['collections' => [
            ['kind' => 'custom_feed', 'id' => 'at://did:plc:bob/app.bsky.feed.generator/news', 'name' => 'News'],
        ]]);

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('social_import_invalid_feed', $this->payload($client)['type']);
    }

    public function testARefusedCollectionWritesNothingElseOfTheImport(): void
    {
        $client = $this->clientWith($this->nothingFollowed());
        $user = $this->signIn($client, 'import-refused-collection@example.test');
        $goneId = $this->subscribe($user, 'https://bsky.app/profile/did:plc:gone/rss')->requireId();

        $this->postImport($client, $this->blueskyAccountOf($user), [
            'subscribe' => [['identity' => 'did:plc:bob', 'title' => 'Bob']],
            'unsubscribe' => [$goneId],
            'collections' => [
                ['kind' => 'home', 'id' => null, 'name' => 'Home'],
                ['kind' => 'list', 'id' => '42', 'name' => 'Friends'],
            ],
        ]);

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->entityManager()->clear();
        self::assertNotNull($this->entityManager()->find(Subscription::class, $goneId));
        self::assertNull($this->entityManager()->getRepository(Feed::class)->findOneBy([
            'url' => 'https://bsky.app/profile/did:plc:bob/rss',
        ]));
        self::assertSame(0, $this->entityManager()->getRepository(SocialFeed::class)->count());
    }

    public function testImportRefusesAnUnknownCollectionKind(): void
    {
        $client = $this->clientWith($this->nothingFollowed());
        $user = $this->signIn($client, 'import-unknown-kind@example.test');

        $this->postImport($client, $this->blueskyAccountOf($user), ['collections' => [
            ['kind' => 'starter_pack', 'id' => 'x', 'name' => 'Pack'],
        ]]);

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(['collections[0].kind'], $this->invalidFields($client));
    }

    public function testImportTakesAtMostAHundredCollections(): void
    {
        $client = $this->clientWith($this->nothingFollowed());
        $user = $this->signIn($client, 'import-too-many-collections@example.test');
        $collections = array_map(
            static fn (int $index): array => ['kind' => 'list', 'id' => (string) $index, 'name' => 'List ' . $index],
            range(1, 101),
        );

        $this->postImport($client, $this->stored(SocialAccounts::mastodonOf($user)), ['collections' => $collections]);

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(['collections'], $this->invalidFields($client));
        self::assertSame(0, $this->entityManager()->getRepository(SocialFeed::class)->count());
    }

    /** @return list<int|string> */
    private function invalidFields(KernelBrowser $client): array
    {
        $problem = $this->payload($client);
        self::assertSame('validation_error', $problem['type']);
        self::assertIsArray($problem['errors']);

        return array_keys($problem['errors']);
    }

    /** @param array<string, mixed> $body */
    private function postImport(KernelBrowser $client, SocialAccount $account, array $body): void
    {
        $client->request(
            'POST',
            sprintf('/api/me/social-accounts/%d/import', $account->requireId()),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
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

    /** @return array{kind: string, id: ?string, name: string, state: string, subscriptionId: null} */
    private static function collection(string $kind, ?string $id, string $name): array
    {
        return ['kind' => $kind, 'id' => $id, 'name' => $name, 'state' => 'new', 'subscriptionId' => null];
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
        return $this->stored(SocialAccounts::blueskyOf($user));
    }

    private function stored(SocialAccount $account): SocialAccount
    {
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
