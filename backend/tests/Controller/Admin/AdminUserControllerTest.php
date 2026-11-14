<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Entity\UserIdentity;
use App\Enum\UserStatus;
use App\Service\Library\Subscription\SubscriptionService;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\QueryRecorder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The account list and detail an admin reads. Tokens are minted straight from the JWT manager rather than through
 * POST /api/auth/login, so these cases never touch the login throttler's filesystem pool.
 */
final class AdminUserControllerTest extends ApiTestCase
{
    private const LIST = '/api/admin/users';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = self::createClient();
    }

    private function call(string $method, string $uri, ?string $token = null): void
    {
        $this->client->request(
            $method,
            $uri,
            server: null === $token ? [] : $this->bearer($token),
        );
    }

    /**
     * A named top-level section of a decoded payload — 'user' or 'footprint'
     * on the detail response — narrowed from mixed so nested keys can be read.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function section(array $body, string $key): array
    {
        $section = $body[$key];
        self::assertIsArray($section);

        /** @var array<string, mixed> $section */
        return $section;
    }

    /**
     * The row for one address, from a decoded list payload.
     *
     * @return array<string, mixed>
     */
    private function rowFor(string $email): array
    {
        $users = $this->payload($this->client)['users'];
        self::assertIsArray($users);

        foreach ($users as $row) {
            self::assertIsArray($row);
            if (($row['email'] ?? null) === $email) {
                /** @var array<string, mixed> $row */
                return $row;
            }
        }

        self::fail(sprintf('no row for %s in the admin list', $email));
    }

    /** Links a provider identity to an already-persisted user. */
    private function link(User $user, string $provider, string $providerUserId): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new UserIdentity($user, $provider, $providerUserId, new \DateTimeImmutable()));
        $entityManager->flush();
    }

    /**
     * Gives the user real subscriptions and tags — deliberately different
     * counts, so a field swap between the two batched reads fails as loudly as
     * a keying miss does.
     */
    private function seedFootprint(User $user, int $subscriptionCount, int $tagCount): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        for ($index = 0; $index < $subscriptionCount; ++$index) {
            $feed = new Feed(sprintf('https://example.com/footprint-%d-%d.xml', $user->requireId(), $index));
            $entityManager->persist($feed);
            $entityManager->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01 00:00:00')));
        }

        for ($index = 0; $index < $tagCount; ++$index) {
            $entityManager->persist(new Tag($user, sprintf('footprint-tag-%d-%d', $user->requireId(), $index)));
        }

        $entityManager->flush();
    }

    public function testAnonymousIsRejectedWithProblemJson(): void
    {
        $this->call('GET', self::LIST);

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertSame('unauthorized', $this->payload($this->client)['type']);
    }

    /**
     * The whole authorization matrix, not one sampled verb: a missing rule on a
     * single route is exactly the kind of hole that ships unnoticed.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function adminRoutes(): iterable
    {
        yield 'list' => ['GET', self::LIST];
        yield 'detail' => ['GET', self::LIST . '/%d'];
    }

    #[DataProvider('adminRoutes')]
    public function testNonAdminIsForbiddenOnEveryRoute(string $method, string $uriTemplate): void
    {
        $plain = $this->factory()->create('plain@example.com');
        $target = $this->factory()->create('target@example.com', status: UserStatus::PendingApproval);
        $token = $this->tokenFor($plain);

        $this->call($method, sprintf($uriTemplate, $target->requireId()), $token);

        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

    #[DataProvider('adminRoutes')]
    public function testAnonymousIsUnauthorizedOnEveryRoute(string $method, string $uriTemplate): void
    {
        $target = $this->factory()->create('target@example.com', status: UserStatus::PendingApproval);

        $this->call($method, sprintf($uriTemplate, $target->requireId()));

        self::assertResponseStatusCodeSame(401);
    }

    public function testAdminListsUsersWithoutLeakingSecrets(): void
    {
        $admin = $this->admin();
        $this->factory()->create('waiting@example.com', status: UserStatus::PendingApproval);

        $this->call('GET', self::LIST, $this->tokenFor($admin));

        self::assertResponseIsSuccessful();
        $payload = $this->payload($this->client);
        self::assertIsArray($payload['users']);
        self::assertCount(2, $payload['users']);

        $emails = array_column($payload['users'], 'email');
        self::assertContains('boss@example.com', $emails);
        self::assertContains('waiting@example.com', $emails);

        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('passwordHash', $body);
        self::assertStringNotContainsString('correct-horse-battery', $body);

        foreach ($payload['users'] as $entry) {
            self::assertIsArray($entry);
            self::assertSame(
                [
                    'id', 'email', 'status', 'roles', 'createdAt', 'approvedAt', 'identities',
                    'feedsCount', 'tagsCount', 'lastLoginAt', 'trialEndsAt', 'maxSubscriptions',
                ],
                array_keys($entry),
            );
        }
    }

    public function testStatusFilterNarrowsTheList(): void
    {
        $admin = $this->admin();
        $this->factory()->create('waiting@example.com', status: UserStatus::PendingApproval);
        $this->factory()->create('gone@example.com', status: UserStatus::Rejected);

        $this->call('GET', self::LIST . '?status=pending_approval', $this->tokenFor($admin));

        self::assertResponseIsSuccessful();
        $payload = $this->payload($this->client);
        self::assertIsArray($payload['users']);
        self::assertSame(['waiting@example.com'], array_column($payload['users'], 'email'));
    }

    public function testUnknownStatusFilterIsAValidationError(): void
    {
        $admin = $this->admin();

        $this->call('GET', self::LIST . '?status=not-a-status', $this->tokenFor($admin));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('validation_error', $this->payload($this->client)['type']);
    }

    /**
     * The reason this column exists. An OAuth account has no verification mail
     * for an admin to chase and may carry a synthetic `@oauth.invalid` address,
     * so without this the queue shows two anomalies and no explanation.
     */
    public function testTheAdminListShowsWhichProvidersAnAccountSignedUpWith(): void
    {
        $admin = $this->admin();
        $user = $this->factory()->create('oauth@example.com', status: UserStatus::PendingApproval);
        $this->link($user, 'google', 'sub-1');

        $this->call('GET', self::LIST, $this->tokenFor($admin));

        self::assertResponseIsSuccessful();
        self::assertSame(['google'], $this->rowFor('oauth@example.com')['identities']);
    }

    public function testAPasswordOnlyAccountListsNoIdentities(): void
    {
        $admin = $this->admin();
        $this->factory()->create('bob@example.com', status: UserStatus::PendingApproval);

        $this->call('GET', self::LIST, $this->tokenFor($admin));

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->rowFor('bob@example.com')['identities']);
    }

    /**
     * The spec allows one user to hold several identities, so the column is a
     * list and not a single value.
     */
    public function testAnAccountWithTwoProvidersListsBoth(): void
    {
        $admin = $this->admin();
        $user = $this->factory()->create('both@example.com', status: UserStatus::PendingApproval);
        $this->link($user, 'google', 'sub-1');
        $this->link($user, 'apple', 'sub-2');

        $this->call('GET', self::LIST, $this->tokenFor($admin));

        self::assertResponseIsSuccessful();
        $providers = $this->rowFor('both@example.com')['identities'];
        self::assertIsArray($providers);
        sort($providers);
        self::assertSame(['apple', 'google'], $providers);
    }

    /**
     * The N+1 guard, the only assertion here a per-row provider lookup would fail: the body is identical either way.
     * User has no ORM association to UserIdentity, so the batched read is hand-written and easy to "simplify".
     */
    public function testTheProviderColumnCostsOneQueryHoweverManyUsersAreListed(): void
    {
        $admin = $this->admin();
        for ($index = 0; $index < 7; ++$index) {
            $user = $this->factory()->create("user{$index}@example.com", status: UserStatus::PendingApproval);
            $this->link($user, 'google', "sub-{$index}");
        }

        $token = $this->tokenFor($admin);

        // Reset after seeding, so the INSERTs above are not counted.
        /** @var QueryRecorder $recorder */
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        $recorder->reset();

        $this->call('GET', self::LIST, $token);

        self::assertResponseIsSuccessful();
        $listed = $this->payload($this->client)['users'];
        self::assertIsArray($listed);
        self::assertCount(8, $listed);

        $reads = $recorder->queriesMatching('from user_identity');
        self::assertCount(
            1,
            $reads,
            "the provider column must be one batched read, got:\n" . implode("\n", $reads),
        );
    }

    /**
     * The empty case, which the batched read has to special-case: an `IN ()`
     * with no values is a SQL syntax error on both engines.
     */
    public function testAFilterMatchingNobodyDoesNotBreakTheProviderLookup(): void
    {
        $admin = $this->admin();

        $this->call('GET', self::LIST . '?status=suspended', $this->tokenFor($admin));

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->payload($this->client)['users']);
    }

    public function testTheListCarriesFootprintCountsAndTheLastLoginStamp(): void
    {
        $admin = $this->admin();
        $token = $this->tokenFor($admin);

        $user = $this->factory()->create(
            'busy@example.com',
            lastLoginAt: new \DateTimeImmutable('2026-07-29 09:00:00'),
        );
        // 2 and 3, not equal and not zero: a keying miss reports 0/0 and a
        // swapped feedsCount/tagsCount reports 3/2, so both fail loudly.
        $this->seedFootprint($user, subscriptionCount: 2, tagCount: 3);

        $this->call('GET', self::LIST, $token);

        self::assertResponseIsSuccessful();
        $row = $this->rowFor('busy@example.com');
        self::assertSame(2, $row['feedsCount']);
        self::assertSame(3, $row['tagsCount']);
        self::assertIsString($row['lastLoginAt']);
        self::assertStringStartsWith('2026-07-29T09:00:00', $row['lastLoginAt']);
    }

    public function testAUserWithNoFeedsOrTagsReportsZeroForBoth(): void
    {
        $admin = $this->admin();
        $token = $this->tokenFor($admin);
        $this->factory()->create('fresh@example.com');

        $this->call('GET', self::LIST, $token);

        self::assertResponseIsSuccessful();
        $row = $this->rowFor('fresh@example.com');
        self::assertSame(0, $row['feedsCount']);
        self::assertSame(0, $row['tagsCount']);
    }

    public function testAnAccountThatNeverSignedInReportsANullStamp(): void
    {
        $admin = $this->admin();
        $token = $this->tokenFor($admin);
        $this->factory()->create('fresh@example.com');

        $this->call('GET', self::LIST, $token);

        self::assertResponseIsSuccessful();
        self::assertNull($this->rowFor('fresh@example.com')['lastLoginAt']);
    }

    /** Equal read counts at two list sizes prove "one query, however many users"; a single size could not. */
    public function testTheFootprintCountsCostTheSameNumberOfQueriesHoweverManyUsersAreListed(): void
    {
        $admin = $this->admin();
        $token = $this->tokenFor($admin);

        $smallReads = $this->footprintReadCountsAfterListing($token, additionalUsers: 3);
        $largeReads = $this->footprintReadCountsAfterListing($token, additionalUsers: 12);

        self::assertSame(
            $smallReads,
            $largeReads,
            'the batched feed/tag reads must not grow with the number of listed users',
        );
    }

    /**
     * Fetched after the request: each request reboots the kernel, and a recorder fetched before it belongs to the
     * previous boot and records nothing.
     *
     * @return array{feeds: int, tags: int}
     */
    private function footprintReadCountsAfterListing(string $token, int $additionalUsers): array
    {
        for ($index = 0; $index < $additionalUsers; ++$index) {
            $this->factory()->create(\sprintf('counted%d-%d@example.com', $additionalUsers, $index));
        }

        $this->call('GET', self::LIST, $token);

        self::assertResponseIsSuccessful();

        /** @var QueryRecorder $recorder */
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);

        return [
            'feeds' => \count($recorder->queriesMatching('from subscription')),
            'tags' => \count($recorder->queriesMatching('from tag')),
        ];
    }

    /** The empty case, with complete key coverage on the account and footprint sections, so a renamed field fails. */
    public function testTheDetailEndpointReturnsTheAccountItsFootprintAndItsLists(): void
    {
        $admin = $this->admin();
        $token = $this->tokenFor($admin);
        $lastLoginAt = new \DateTimeImmutable('yesterday 09:00:00');
        $user = $this->factory()->create(
            'detailed@example.com',
            roles: ['ROLE_ADMIN'],
            locale: 'de',
            lastLoginAt: $lastLoginAt,
        );

        $this->call('GET', '/api/admin/users/' . $user->getId(), $token);

        self::assertResponseIsSuccessful();
        $body = $this->payload($this->client);
        $account = $this->section($body, 'user');
        $footprint = $this->section($body, 'footprint');

        self::assertSame(
            ['id', 'email', 'status', 'roles', 'locale', 'createdAt', 'approvedAt', 'lastLoginAt', 'identities'],
            array_keys($account),
        );
        self::assertSame($user->getId(), $account['id']);
        self::assertSame('detailed@example.com', $account['email']);
        self::assertSame('active', $account['status']);
        self::assertSame(['ROLE_ADMIN', 'ROLE_USER'], $account['roles']);
        self::assertSame('de', $account['locale']);
        self::assertIsString($account['createdAt']);
        self::assertStringStartsWith('2026-07-01T10:00:00', $account['createdAt']);
        self::assertIsString($account['approvedAt']);
        self::assertStringStartsWith('2026-07-01T10:00:00', $account['approvedAt']);
        self::assertIsString($account['lastLoginAt']);
        self::assertStringStartsWith($lastLoginAt->format('Y-m-d\\TH:i:s'), $account['lastLoginAt']);
        self::assertSame([], $account['identities']);

        self::assertSame(
            ['feedsCount', 'tagsCount', 'feedsLimit', 'staleFeedsCount', 'lastRefreshAt', 'dormant'],
            array_keys($footprint),
        );
        self::assertSame(0, $footprint['feedsCount']);
        self::assertSame(0, $footprint['tagsCount']);
        self::assertSame(SubscriptionService::MAX_SUBSCRIPTIONS_PER_USER, $footprint['feedsLimit']);
        self::assertSame(0, $footprint['staleFeedsCount']);
        self::assertNull($footprint['lastRefreshAt']);
        self::assertFalse($footprint['dormant']);
        self::assertSame([], $body['tags']);
        self::assertSame([], $body['subscriptions']);

        $limits = $this->section($body, 'limits');
        self::assertSame(['trialEndsAt', 'maxSubscriptions'], array_keys($limits));
        self::assertNull($limits['trialEndsAt']);
        self::assertNull($limits['maxSubscriptions']);
    }

    /** An admin-set trial and cap reach the detail screen under their own 'limits' key. */
    public function testTheDetailEndpointIncludesTrialAndSubscriptionLimits(): void
    {
        $admin = $this->admin();
        $token = $this->tokenFor($admin);
        $user = $this->factory()->create(
            'limited@example.com',
            trialEndsAt: new \DateTimeImmutable('2026-08-15 00:00:00'),
            maxSubscriptions: 7,
        );

        $this->call('GET', '/api/admin/users/' . $user->getId(), $token);

        self::assertResponseIsSuccessful();
        $limits = $this->section($this->payload($this->client), 'limits');

        self::assertIsString($limits['trialEndsAt']);
        self::assertStringStartsWith('2026-08-15T00:00:00', $limits['trialEndsAt']);
        self::assertSame(7, $limits['maxSubscriptions']);
    }

    /**
     * The only fixture that records a fetch, so the only one that pins a real lastRefreshAt, staleFeedsCount and
     * lastFetchedAt rather than null and zero.
     */
    public function testTheFootprintAndSubscriptionRowCarryARealFetchTimestamp(): void
    {
        $admin = $this->admin();
        $token = $this->tokenFor($admin);
        $user = $this->factory()->create('stale-fetcher@example.com');

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $feed = new Feed('https://example.com/stale-fetcher.xml');
        $feed->setTitle('Stale Fetcher Weekly');
        $lastFetch = new \DateTimeImmutable('-10 days');
        $feed->recordSuccessfulFetch($lastFetch, 60);
        $entityManager->persist($feed);

        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('-30 days'));
        $entityManager->persist($subscription);
        $entityManager->flush();

        $this->call('GET', '/api/admin/users/' . $user->getId(), $token);

        self::assertResponseIsSuccessful();
        $body = $this->payload($this->client);
        $footprint = $this->section($body, 'footprint');
        $expectedFetchStamp = $lastFetch->format(\DateTimeInterface::ATOM);

        self::assertSame(1, $footprint['staleFeedsCount']);
        self::assertSame($expectedFetchStamp, $footprint['lastRefreshAt']);

        $subscriptions = $body['subscriptions'];
        self::assertIsArray($subscriptions);
        self::assertCount(1, $subscriptions);
        $row = $subscriptions[0];
        self::assertIsArray($row);
        self::assertSame($expectedFetchStamp, $row['lastFetchedAt']);
    }

    public function testTheDetailEndpointNeverLeaksThePasswordHash(): void
    {
        $admin = $this->admin();
        $token = $this->tokenFor($admin);
        $user = $this->factory()->create('secretive@example.com');

        $this->call('GET', '/api/admin/users/' . $user->getId(), $token);

        self::assertResponseIsSuccessful();
        $raw = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('passwordHash', $raw);
        self::assertStringNotContainsString('$2y$', $raw);
    }

    public function testAnUnknownUserIsANotFoundProblem(): void
    {
        $admin = $this->admin();
        $token = $this->tokenFor($admin);

        $this->call('GET', '/api/admin/users/999999', $token);

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

    /**
     * Complete key coverage on every tag and subscription row, distinct non-zero counts (a swap between the two tags
     * shows), and insertion order unlike position order in both lists, so a sort by id or createdAt fails.
     */
    public function testTheDetailListsCarryTheFullTagAndSubscriptionRows(): void
    {
        $admin = $this->admin();
        $token = $this->tokenFor($admin);
        $user = $this->factory()->create('librarian@example.com');

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $tagArchive = new Tag($user, 'archive');
        $tagArchive->setColor('#00ff00');
        $tagArchive->setIcon('archive-icon');
        $tagArchive->setPosition(0);
        $tagReading = new Tag($user, 'reading');
        $tagReading->setColor('#ff0000');
        $tagReading->setIcon('book-icon');
        $tagReading->setPosition(1);
        $entityManager->persist($tagArchive);
        $entityManager->persist($tagReading);

        $feedOne = new Feed('https://example.com/librarian-one.xml');
        $feedOne->setTitle('Librarian Weekly');
        $entityManager->persist($feedOne);
        $feedTwo = new Feed('https://example.com/librarian-two.xml');
        $feedTwo->setTitle('Second Shelf');
        $entityManager->persist($feedTwo);

        // Inserted first but placed second, with its tags attached in reverse position order (reading, then
        // archive), so both sorts are exercised.
        $subscriptionOne = new Subscription($user, $feedOne, new \DateTimeImmutable('2026-07-05 12:00:00'));
        $subscriptionOne->setCustomTitle('My Weekly Read');
        $subscriptionOne->setPosition(1);
        $subscriptionOne->addTag($tagReading, 1);
        $subscriptionOne->addTag($tagArchive, 0);
        $entityManager->persist($subscriptionOne);

        // Inserted SECOND but placed FIRST by position.
        $subscriptionTwo = new Subscription($user, $feedTwo, new \DateTimeImmutable('2026-07-06 12:00:00'));
        $subscriptionTwo->setPosition(0);
        $subscriptionTwo->addTag($tagArchive, 0);
        $entityManager->persist($subscriptionTwo);

        $entityManager->flush();

        $this->call('GET', '/api/admin/users/' . $user->getId(), $token);

        self::assertResponseIsSuccessful();
        $body = $this->payload($this->client);
        $footprint = $this->section($body, 'footprint');

        self::assertSame(2, $footprint['feedsCount']);
        self::assertSame(2, $footprint['tagsCount']);

        $tags = $body['tags'];
        self::assertIsArray($tags);
        self::assertCount(2, $tags);
        foreach ($tags as $tagRow) {
            self::assertIsArray($tagRow);
            self::assertSame(['id', 'name', 'color', 'icon', 'position', 'feedsCount'], array_keys($tagRow));
        }
        self::assertSame(['archive', 'reading'], array_column($tags, 'name'));
        self::assertSame(['#00ff00', '#ff0000'], array_column($tags, 'color'));
        self::assertSame(['archive-icon', 'book-icon'], array_column($tags, 'icon'));
        self::assertSame([0, 1], array_column($tags, 'position'));
        // Distinct and non-zero: archive is on both subscriptions, reading on
        // only one — a swap between the two rows would show up as [1, 2].
        self::assertSame([2, 1], array_column($tags, 'feedsCount'));

        $subscriptions = $body['subscriptions'];
        self::assertIsArray($subscriptions);
        self::assertCount(2, $subscriptions);
        foreach ($subscriptions as $subscriptionRow) {
            self::assertIsArray($subscriptionRow);
            self::assertSame(
                ['id', 'title', 'customTitle', 'url', 'position', 'createdAt', 'lastFetchedAt', 'tags'],
                array_keys($subscriptionRow),
            );
        }

        // Position order (0, 1) — NOT insertion/createdAt order, under which
        // "Librarian Weekly" (created first) would come first.
        self::assertSame(['Second Shelf', 'Librarian Weekly'], array_column($subscriptions, 'title'));
        self::assertSame([0, 1], array_column($subscriptions, 'position'));

        $secondShelfRow = $subscriptions[0];
        self::assertIsArray($secondShelfRow);
        self::assertSame($subscriptionTwo->getId(), $secondShelfRow['id']);
        self::assertNull($secondShelfRow['customTitle']);
        self::assertSame('https://example.com/librarian-two.xml', $secondShelfRow['url']);
        self::assertIsString($secondShelfRow['createdAt']);
        self::assertStringStartsWith('2026-07-06T12:00:00', $secondShelfRow['createdAt']);
        self::assertNull($secondShelfRow['lastFetchedAt']);
        $secondShelfTags = $secondShelfRow['tags'];
        self::assertIsArray($secondShelfTags);
        self::assertSame(['archive'], array_column($secondShelfTags, 'name'));

        $weeklyRow = $subscriptions[1];
        self::assertIsArray($weeklyRow);
        self::assertSame($subscriptionOne->getId(), $weeklyRow['id']);
        self::assertSame('Librarian Weekly', $weeklyRow['title']);
        self::assertSame('My Weekly Read', $weeklyRow['customTitle']);
        self::assertSame('https://example.com/librarian-one.xml', $weeklyRow['url']);
        // Attached [reading, archive] but returned in POSITION order
        // [archive(0), reading(1)].
        $weeklyTags = $weeklyRow['tags'];
        self::assertIsArray($weeklyTags);
        self::assertSame(['archive', 'reading'], array_column($weeklyTags, 'name'));
        foreach ($weeklyTags as $tagOnSubscription) {
            self::assertIsArray($tagOnSubscription);
            self::assertSame(['id', 'name', 'color', 'icon'], array_keys($tagOnSubscription));
        }
        // The row's tag chips carry the same icon as the tag list, so the admin UI renders one glyph for both.
        self::assertSame(['archive-icon', 'book-icon'], array_column($weeklyTags, 'icon'));
    }

    /**
     * One read of the subscription x tag join and one of the tag list, at two very different sizes, each subscription
     * with its own attached tag: a per-subscription tag lookup or a dropped join fetch shows up as growth.
     */
    public function testTheDetailListsCostTheSameNumberOfQueriesHoweverManySubscriptionsAndTagsExist(): void
    {
        $admin = $this->admin();
        $token = $this->tokenFor($admin);

        $smallReads = $this->detailReadCountsForFreshUser($token, count: 2);
        $largeReads = $this->detailReadCountsForFreshUser($token, count: 11);

        $expected = ['subscriptions' => 1, 'tags' => 1];
        self::assertSame($expected, $smallReads, 'two rows should already cost exactly one read per table');
        self::assertSame(
            $smallReads,
            $largeReads,
            'the batched subscription/tag reads must not grow with how many rows the user owns',
        );
    }

    /**
     * Creates a fresh user with $count subscriptions, each carrying its own
     * attached tag, requests the detail screen, and returns how many queries
     * touched each table.
     *
     * @return array{subscriptions: int, tags: int}
     */
    private function detailReadCountsForFreshUser(string $token, int $count): array
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = $this->factory()->create(sprintf('detail-%d@example.com', $count));

        for ($index = 0; $index < $count; ++$index) {
            $feed = new Feed(sprintf('https://example.com/detail-%d-%d.xml', $user->requireId(), $index));
            $entityManager->persist($feed);
            $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01 00:00:00'));
            $tag = new Tag($user, sprintf('detail-tag-%d-%d', $user->requireId(), $index));
            $entityManager->persist($tag);
            $subscription->addTag($tag);
            $entityManager->persist($subscription);
        }
        $entityManager->flush();

        $this->call('GET', self::LIST . '/' . $user->requireId(), $token);

        self::assertResponseIsSuccessful();

        /** @var QueryRecorder $recorder */
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);

        return [
            'subscriptions' => \count($recorder->queriesMatching('from subscription')),
            'tags' => \count($recorder->queriesMatching('from tag')),
        ];
    }
}
