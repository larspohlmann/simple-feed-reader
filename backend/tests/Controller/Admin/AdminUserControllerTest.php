<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Entity\UserIdentity;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Service\Subscription\SubscriptionService;
use App\Tests\Support\EnablesMailInTests;
use App\Tests\Support\QueryRecorder;
use App\Tests\Support\ReloadsEntities;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The approval queue. Tokens are minted straight from the JWT manager rather
 * than through POST /api/auth/login, so these cases never touch the login
 * throttler's filesystem pool and cannot be poisoned by it.
 */
final class AdminUserControllerTest extends WebTestCase
{
    use EnablesMailInTests;
    use ReloadsEntities;

    private const LIST = '/api/admin/users';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = self::createClient();
        $this->seedEnabledMailInstance();
    }

    private function factory(): UserFactory
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        return new UserFactory($entityManager, $hasher);
    }

    private function tokenFor(User $user): string
    {
        /** @var JWTTokenManagerInterface $manager */
        $manager = self::getContainer()->get(JWTTokenManagerInterface::class);

        return $manager->create($user);
    }

    private function admin(string $email = 'boss@example.com'): User
    {
        return $this->factory()->create($email, roles: ['ROLE_ADMIN']);
    }

    private function call(string $method, string $uri, ?string $token = null): void
    {
        $this->client->request(
            $method,
            $uri,
            server: null === $token ? [] : ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
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
        $users = $this->payload()['users'];
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
        self::assertSame('unauthorized', $this->payload()['type']);
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
        yield 'approve' => ['POST', self::LIST . '/%d/approve'];
        yield 'reject' => ['POST', self::LIST . '/%d/reject'];
        yield 'suspend' => ['POST', self::LIST . '/%d/suspend'];
        yield 'reset-password' => ['POST', self::LIST . '/%d/reset-password'];
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
        $payload = $this->payload();
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
        $payload = $this->payload();
        self::assertIsArray($payload['users']);
        self::assertSame(['waiting@example.com'], array_column($payload['users'], 'email'));
    }

    public function testUnknownStatusFilterIsAValidationError(): void
    {
        $admin = $this->admin();

        $this->call('GET', self::LIST . '?status=not-a-status', $this->tokenFor($admin));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('validation_error', $this->payload()['type']);
    }

    public function testApproveActivatesTheUserAndSendsExactlyOneMail(): void
    {
        $admin = $this->admin();
        $target = $this->factory()->create('waiting@example.com', status: UserStatus::PendingApproval);
        $id = $target->requireId();

        $this->call('POST', self::LIST . '/' . $id . '/approve', $this->tokenFor($admin));

        self::assertResponseIsSuccessful();
        self::assertSame('active', $this->payload()['status']);
        self::assertEmailCount(1);

        $reloaded = $this->reload($target);
        self::assertSame(UserStatus::Active, $reloaded->getStatus());
        self::assertNotNull($reloaded->getApprovedAt());
    }

    /**
     * Approving an unverified user overrides double opt-in on purpose (the queue lists every status), so it is a
     * first-time grant and mails like one.
     */
    public function testApprovingAnUnverifiedUserAlsoSendsTheApprovalMail(): void
    {
        $admin = $this->admin();
        $target = $this->factory()->create('unverified@example.com', status: UserStatus::PendingVerification);
        $id = $target->requireId();

        $this->call('POST', self::LIST . '/' . $id . '/approve', $this->tokenFor($admin));

        self::assertResponseIsSuccessful();
        self::assertSame('active', $this->payload()['status']);
        self::assertEmailCount(1, message: 'a first-time grant is announced whichever queue it came from');

        self::assertSame(UserStatus::Active, $this->reload($target)->getStatus());
    }

    /**
     * A second click is a no-op, not a second mail: the mail rides the
     * pending_approval -> active transition, and an already-active user is not
     * making that transition.
     */
    public function testApprovingAnAlreadyActiveUserIsIdempotentAndSilent(): void
    {
        $admin = $this->admin();
        $target = $this->factory()->create('already@example.com', status: UserStatus::Active);
        $uri = self::LIST . '/' . $target->requireId() . '/approve';

        $this->call('POST', $uri, $this->tokenFor($admin));

        self::assertResponseIsSuccessful();
        self::assertSame('active', $this->payload()['status']);
        self::assertEmailCount(0, message: 'no queue was left, so there is nothing to announce');
    }

    /**
     * A rejected user never had access (rejection happens only from pending_approval), so approving one is a
     * first-time grant and mails. Only suspended and already-active accounts stay silent.
     */
    public function testApprovingARejectedUserSendsTheApprovalMail(): void
    {
        $admin = $this->admin();
        $target = $this->factory()->create('reconsidered@example.com', status: UserStatus::Rejected);
        $id = $target->requireId();

        $this->call('POST', self::LIST . '/' . $id . '/approve', $this->tokenFor($admin));

        self::assertResponseIsSuccessful();
        self::assertSame('active', $this->payload()['status']);
        self::assertEmailCount(1, message: 'a reversed rejection grants access for the first time');

        $reloaded = $this->reload($target);
        self::assertSame(UserStatus::Active, $reloaded->getStatus());
        self::assertNotNull($reloaded->getApprovedAt());
    }

    /**
     * Reinstatement: approve is the only route back from suspended, so it must
     * still work — but a suspended user already had access, and telling them
     * their account "has been approved" would be nonsense.
     */
    public function testReinstatingASuspendedUserActivatesThemWithoutMailing(): void
    {
        $admin = $this->admin();
        $target = $this->factory()->create('back@example.com', status: UserStatus::Suspended);
        $id = $target->requireId();

        $this->call('POST', self::LIST . '/' . $id . '/approve', $this->tokenFor($admin));

        self::assertResponseIsSuccessful();
        self::assertSame('active', $this->payload()['status']);
        self::assertEmailCount(0, message: 'restoring access the user already had is not an announcement');

        $reloaded = $this->reload($target);
        self::assertSame(UserStatus::Active, $reloaded->getStatus());
        self::assertNotNull(
            $reloaded->getApprovedAt(),
            'approvedAt is the audit trail for when access was granted, reinstatement included',
        );
    }

    /**
     * approvedAt answers "when was this account last granted access", not "when
     * did it first clear the queue" — so reinstatement overwrites it.
     */
    public function testReinstatementOverwritesTheEarlierApprovedAt(): void
    {
        $admin = $this->admin();
        $target = $this->factory()->create('back@example.com', status: UserStatus::Suspended);
        $original = new \DateTimeImmutable('2020-01-01 00:00:00');
        $target->approve($original);
        $target->suspend();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->flush();
        $id = $target->requireId();

        $this->call('POST', self::LIST . '/' . $id . '/approve', $this->tokenFor($admin));

        self::assertResponseIsSuccessful();
        $approvedAt = $this->reload($target)->getApprovedAt();
        self::assertNotNull($approvedAt);
        self::assertGreaterThan($original, $approvedAt);
    }

    public function testRejectSetsTheStatusAndSendsNoMail(): void
    {
        $admin = $this->admin();
        $target = $this->factory()->create('waiting@example.com', status: UserStatus::PendingApproval);
        $id = $target->requireId();

        $this->call('POST', self::LIST . '/' . $id . '/reject', $this->tokenFor($admin));

        self::assertResponseIsSuccessful();
        self::assertSame('rejected', $this->payload()['status']);
        self::assertEmailCount(0);
        self::assertSame(UserStatus::Rejected, $this->reload($target)->getStatus());
    }

    public function testSuspendSetsTheStatusAndSendsNoMail(): void
    {
        $admin = $this->admin();
        $target = $this->factory()->create('member@example.com');
        $id = $target->requireId();

        $this->call('POST', self::LIST . '/' . $id . '/suspend', $this->tokenFor($admin));

        self::assertResponseIsSuccessful();
        self::assertSame('suspended', $this->payload()['status']);
        self::assertEmailCount(0);
        self::assertSame(UserStatus::Suspended, $this->reload($target)->getStatus());
    }

    /**
     * Suspension is the revocation mechanism — there are no refresh tokens to
     * expire, so it has to bite on the target's very next request.
     */
    public function testSuspendRevokesTheTargetsExistingToken(): void
    {
        $admin = $this->admin();
        $target = $this->factory()->create('member@example.com');
        $targetToken = $this->tokenFor($target);

        $this->call('GET', '/api/me', $targetToken);
        self::assertResponseIsSuccessful();

        $this->call('POST', self::LIST . '/' . $target->requireId() . '/suspend', $this->tokenFor($admin));
        self::assertResponseIsSuccessful();

        $this->call('GET', '/api/me', $targetToken);
        self::assertResponseStatusCodeSame(401);
    }

    /**
     * The mailless-instance recovery path: the admin relays this value out of band, so the response is the only
     * place it ever appears.
     */
    public function testResetPasswordReturnsAFreshGeneratedSecretOnce(): void
    {
        $admin = $this->admin();
        $target = $this->factory()->create('resettable@example.com');
        $id = $target->requireId();
        $originalChangedAt = $target->getPasswordChangedAt();

        $this->call('POST', self::LIST . '/' . $id . '/reset-password', $this->tokenFor($admin));

        self::assertResponseIsSuccessful();
        $payload = $this->payload();
        self::assertIsString($payload['password']);
        self::assertNotSame('', $payload['password']);

        $reloaded = $this->reload($target);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($reloaded, $payload['password']));
        self::assertNotNull($reloaded->getPasswordChangedAt());
        self::assertGreaterThan($originalChangedAt, $reloaded->getPasswordChangedAt());
    }

    public function testResetPasswordIsAdminOnly(): void
    {
        $plain = $this->factory()->create('plain@example.com');
        $target = $this->factory()->create('target@example.com');

        $this->call(
            'POST',
            self::LIST . '/' . $target->requireId() . '/reset-password',
            $this->tokenFor($plain),
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testUnknownUserIsNotFound(): void
    {
        $admin = $this->admin();

        $this->call('POST', self::LIST . '/999999/approve', $this->tokenFor($admin));

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertSame('not_found', $this->payload()['type']);
        self::assertSame('User not found.', $this->payload()['detail']);
    }

    public function testAdminCannotSuspendThemselves(): void
    {
        $admin = $this->admin();

        $this->call('POST', self::LIST . '/' . $admin->requireId() . '/suspend', $this->tokenFor($admin));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('validation_error', $this->payload()['type']);
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
        $listed = $this->payload()['users'];
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
        self::assertSame([], $this->payload()['users']);
    }

    public function testAdminCannotRejectThemselves(): void
    {
        $admin = $this->admin();

        $this->call('POST', self::LIST . '/' . $admin->requireId() . '/reject', $this->tokenFor($admin));

        self::assertResponseStatusCodeSame(422);
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
        $user = $this->factory()->create(
            'detailed@example.com',
            roles: ['ROLE_ADMIN'],
            locale: 'de',
            lastLoginAt: new \DateTimeImmutable('2026-07-29 09:00:00'),
        );

        $this->call('GET', '/api/admin/users/' . $user->getId(), $token);

        self::assertResponseIsSuccessful();
        $body = $this->payload();
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
        self::assertStringStartsWith('2026-07-29T09:00:00', $account['lastLoginAt']);
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
        $limits = $this->section($this->payload(), 'limits');

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
        $body = $this->payload();
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
        $body = $this->payload();
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

    public function testAnAdminDeletesAnotherAccount(): void
    {
        $factory = $this->factory();
        $admin = $factory->create('admin-del@example.com', roles: ['ROLE_ADMIN']);
        $target = $factory->create('victim@example.com');
        $targetId = $target->requireId();

        $this->client->request('DELETE', self::LIST . '/' . $targetId, server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($admin),
        ]);

        self::assertResponseStatusCodeSame(204);
    }

    public function testAnAdminCannotDeleteThemselvesThroughTheAdminApi(): void
    {
        $admin = $this->factory()->create('self-del@example.com', roles: ['ROLE_ADMIN']);
        $this->factory()->create('spare-admin@example.com', roles: ['ROLE_ADMIN']);

        $this->client->request('DELETE', self::LIST . '/' . $admin->getId(), server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($admin),
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * Not the 409 last-admin refusal: both admins are active, so that guard has nothing to refuse. It proves the
     * deleted admin's token no longer authenticates (401) and that the right account went.
     */
    public function testAnAdminDeletedByAnotherAdminCanNoLongerAuthenticate(): void
    {
        $factory = $this->factory();
        $soleAdmin = $factory->create('sole-admin@example.com', roles: ['ROLE_ADMIN']);
        $deputy = $factory->create('deputy@example.com', roles: ['ROLE_ADMIN']);
        // Minted before the delete: Doctrine nulls a removed entity's id on
        // this SAME object across the test kernel's reset (not rebuilt)
        // EntityManager, so minting after would token a now-id-less $deputy.
        $deputyToken = $this->tokenFor($deputy);

        $this->client->request('DELETE', self::LIST . '/' . $deputy->getId(), server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($soleAdmin),
        ]);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('DELETE', self::LIST . '/' . $soleAdmin->getId(), server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $deputyToken,
        ]);
        self::assertResponseStatusCodeSame(401);

        self::assertNotNull(
            self::getContainer()->get(UserRepository::class)->find($soleAdmin->getId()),
        );
    }

    /**
     * The 409 path: countActiveAdmins() is status-aware, so deleting a non-active admin is refused when the caller is
     * the sole active admin.
     */
    public function testDeletingASuspendedAdminIsRefusedWhenTheCallerIsTheSoleActiveAdmin(): void
    {
        $factory = $this->factory();
        $caller = $factory->create('sole-active-admin@example.com', roles: ['ROLE_ADMIN']);
        $suspended = $factory->create(
            'suspended-admin@example.com',
            roles: ['ROLE_ADMIN'],
            status: UserStatus::Suspended,
        );

        $this->client->request('DELETE', self::LIST . '/' . $suspended->getId(), server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($caller),
        ]);

        self::assertResponseStatusCodeSame(409);
    }

    public function testANonAdminCannotDeleteAnAccount(): void
    {
        $factory = $this->factory();
        $factory->create('an-admin@example.com', roles: ['ROLE_ADMIN']);
        $plainUser = $factory->create('plain@example.com');
        $target = $factory->create('other-target@example.com');

        $this->client->request('DELETE', self::LIST . '/' . $target->getId(), server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($plainUser),
        ]);

        self::assertResponseStatusCodeSame(403);
    }
}
