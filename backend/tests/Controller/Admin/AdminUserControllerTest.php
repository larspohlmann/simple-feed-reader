<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\User;
use App\Entity\UserIdentity;
use App\Enum\UserStatus;
use App\Tests\Support\QueryRecorder;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
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
    private const LIST = '/api/admin/users';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = self::createClient();
    }

    private function factory(): UserFactory
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        return new UserFactory($em, $hasher);
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
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new UserIdentity($user, $provider, $providerUserId, new \DateTimeImmutable()));
        $em->flush();
    }

    /** Re-reads through the CURRENT kernel: the seeding EM belongs to a rebooted one. */
    private function reload(int $id): User
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $user = $em->getRepository(User::class)->find($id);
        self::assertInstanceOf(User::class, $user);

        return $user;
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
        yield 'approve' => ['POST', self::LIST . '/%d/approve'];
        yield 'reject' => ['POST', self::LIST . '/%d/reject'];
        yield 'suspend' => ['POST', self::LIST . '/%d/suspend'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('adminRoutes')]
    public function testNonAdminIsForbiddenOnEveryRoute(string $method, string $uriTemplate): void
    {
        $plain = $this->factory()->create('plain@example.com');
        $target = $this->factory()->create('target@example.com', status: UserStatus::PendingApproval);
        $token = $this->tokenFor($plain);

        $this->call($method, sprintf($uriTemplate, (int) $target->getId()), $token);

        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('adminRoutes')]
    public function testAnonymousIsUnauthorizedOnEveryRoute(string $method, string $uriTemplate): void
    {
        $target = $this->factory()->create('target@example.com', status: UserStatus::PendingApproval);

        $this->call($method, sprintf($uriTemplate, (int) $target->getId()));

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
                ['id', 'email', 'status', 'roles', 'createdAt', 'approvedAt', 'identities'],
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
        $id = (int) $target->getId();

        $this->call('POST', self::LIST . '/' . $id . '/approve', $this->tokenFor($admin));

        self::assertResponseIsSuccessful();
        self::assertSame('active', $this->payload()['status']);
        self::assertEmailCount(1);

        $reloaded = $this->reload($id);
        self::assertSame(UserStatus::Active, $reloaded->getStatus());
        self::assertNotNull($reloaded->getApprovedAt());
    }

    /**
     * The other first-time grant. An admin approving someone who never clicked
     * their verification link is overriding double opt-in — deliberate, since
     * the queue lists every status — so the grant is as real as the queued one
     * and the mail says the same true thing.
     */
    public function testApprovingAnUnverifiedUserAlsoSendsTheApprovalMail(): void
    {
        $admin = $this->admin();
        $target = $this->factory()->create('unverified@example.com', status: UserStatus::PendingVerification);
        $id = (int) $target->getId();

        $this->call('POST', self::LIST . '/' . $id . '/approve', $this->tokenFor($admin));

        self::assertResponseIsSuccessful();
        self::assertSame('active', $this->payload()['status']);
        self::assertEmailCount(1, message: 'a first-time grant is announced whichever queue it came from');

        self::assertSame(UserStatus::Active, $this->reload($id)->getStatus());
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
        $uri = self::LIST . '/' . (int) $target->getId() . '/approve';

        $this->call('POST', $uri, $this->tokenFor($admin));

        self::assertResponseIsSuccessful();
        self::assertSame('active', $this->payload()['status']);
        self::assertEmailCount(0, message: 'no queue was left, so there is nothing to announce');
    }

    /**
     * Approving a REJECTED account is a first-time grant, not a reinstatement,
     * and this used to be classified the wrong way.
     *
     * The rule is "the mail means you have been granted access for the first
     * time". Rejection only ever happens from pending_approval — reject() is
     * how an admin empties the queue — so a rejected user has NEVER had access.
     * Reversing that decision hands them access for the first time, and it is
     * the one case where the user is guaranteed to be waiting to hear: they
     * applied, and as far as they know nothing happened. Staying silent left
     * them with a working account they had no reason to try.
     *
     * Only suspended (access genuinely restored) and already-active (no-op)
     * remain silent.
     */
    public function testApprovingARejectedUserSendsTheApprovalMail(): void
    {
        $admin = $this->admin();
        $target = $this->factory()->create('reconsidered@example.com', status: UserStatus::Rejected);
        $id = (int) $target->getId();

        $this->call('POST', self::LIST . '/' . $id . '/approve', $this->tokenFor($admin));

        self::assertResponseIsSuccessful();
        self::assertSame('active', $this->payload()['status']);
        self::assertEmailCount(1, message: 'a reversed rejection grants access for the first time');

        $reloaded = $this->reload($id);
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
        $id = (int) $target->getId();

        $this->call('POST', self::LIST . '/' . $id . '/approve', $this->tokenFor($admin));

        self::assertResponseIsSuccessful();
        self::assertSame('active', $this->payload()['status']);
        self::assertEmailCount(0, message: 'restoring access the user already had is not an announcement');

        $reloaded = $this->reload($id);
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
        $target->setApprovedAt($original);

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->flush();
        $id = (int) $target->getId();

        $this->call('POST', self::LIST . '/' . $id . '/approve', $this->tokenFor($admin));

        self::assertResponseIsSuccessful();
        $approvedAt = $this->reload($id)->getApprovedAt();
        self::assertNotNull($approvedAt);
        self::assertGreaterThan($original, $approvedAt);
    }

    public function testRejectSetsTheStatusAndSendsNoMail(): void
    {
        $admin = $this->admin();
        $target = $this->factory()->create('waiting@example.com', status: UserStatus::PendingApproval);
        $id = (int) $target->getId();

        $this->call('POST', self::LIST . '/' . $id . '/reject', $this->tokenFor($admin));

        self::assertResponseIsSuccessful();
        self::assertSame('rejected', $this->payload()['status']);
        self::assertEmailCount(0);
        self::assertSame(UserStatus::Rejected, $this->reload($id)->getStatus());
    }

    public function testSuspendSetsTheStatusAndSendsNoMail(): void
    {
        $admin = $this->admin();
        $target = $this->factory()->create('member@example.com');
        $id = (int) $target->getId();

        $this->call('POST', self::LIST . '/' . $id . '/suspend', $this->tokenFor($admin));

        self::assertResponseIsSuccessful();
        self::assertSame('suspended', $this->payload()['status']);
        self::assertEmailCount(0);
        self::assertSame(UserStatus::Suspended, $this->reload($id)->getStatus());
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

        $this->call('POST', self::LIST . '/' . (int) $target->getId() . '/suspend', $this->tokenFor($admin));
        self::assertResponseIsSuccessful();

        $this->call('GET', '/api/me', $targetToken);
        self::assertResponseStatusCodeSame(401);
    }

    public function testUnknownUserIsNotFound(): void
    {
        $admin = $this->admin();

        $this->call('POST', self::LIST . '/999999/approve', $this->tokenFor($admin));

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertSame('not_found', $this->payload()['type']);
    }

    public function testAdminCannotSuspendThemselves(): void
    {
        $admin = $this->admin();

        $this->call('POST', self::LIST . '/' . (int) $admin->getId() . '/suspend', $this->tokenFor($admin));

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
     * The N+1 guard, and the only assertion here that can fail: the response
     * body is byte-identical whether the providers are read in one query or in
     * one per row, so nothing above notices a loop.
     *
     * User holds no ORM association to UserIdentity — Plan 1 kept that
     * relationship one-directional and lets the database FK cascade the deletes
     * — so the batched read is hand-written and a future edit could easily
     * "simplify" it into a per-user lookup.
     */
    public function testTheProviderColumnCostsOneQueryHoweverManyUsersAreListed(): void
    {
        $admin = $this->admin();
        for ($i = 0; $i < 7; ++$i) {
            $user = $this->factory()->create("user{$i}@example.com", status: UserStatus::PendingApproval);
            $this->link($user, 'google', "sub-{$i}");
        }

        $token = $this->tokenFor($admin);

        // Cleared after seeding, so the INSERTs above are not counted. The
        // recorder outlives the kernel reboot the request triggers, because
        // dama/doctrine-test-bundle keeps one connection for the whole process
        // and the middleware is bound to it, not to the container.
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

        $this->call('POST', self::LIST . '/' . (int) $admin->getId() . '/reject', $this->tokenFor($admin));

        self::assertResponseStatusCodeSame(422);
    }
}
