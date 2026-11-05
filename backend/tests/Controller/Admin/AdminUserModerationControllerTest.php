<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\User;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\EnablesMailInTests;
use App\Tests\Support\ReloadsEntities;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Approve, reject, suspend, reset a password, delete. Tokens are minted straight from the JWT manager rather than
 * through POST /api/auth/login, so these cases never touch the login throttler's filesystem pool.
 */
final class AdminUserModerationControllerTest extends ApiTestCase
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

    private function admin(string $email = 'boss@example.com'): User
    {
        return $this->factory()->create($email, roles: ['ROLE_ADMIN']);
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
     * The whole authorization matrix, not one sampled verb: a missing rule on a
     * single route is exactly the kind of hole that ships unnoticed.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function adminRoutes(): iterable
    {
        yield 'approve' => ['POST', self::LIST . '/%d/approve'];
        yield 'reject' => ['POST', self::LIST . '/%d/reject'];
        yield 'suspend' => ['POST', self::LIST . '/%d/suspend'];
        yield 'reset-password' => ['POST', self::LIST . '/%d/reset-password'];
        yield 'delete' => ['DELETE', self::LIST . '/%d'];
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

    public function testApproveActivatesTheUserAndSendsExactlyOneMail(): void
    {
        $admin = $this->admin();
        $target = $this->factory()->create('waiting@example.com', status: UserStatus::PendingApproval);
        $id = $target->requireId();

        $this->call('POST', self::LIST . '/' . $id . '/approve', $this->tokenFor($admin));

        self::assertResponseIsSuccessful();
        self::assertSame('active', $this->payload($this->client)['status']);
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
        self::assertSame('active', $this->payload($this->client)['status']);
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
        self::assertSame('active', $this->payload($this->client)['status']);
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
        self::assertSame('active', $this->payload($this->client)['status']);
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
        self::assertSame('active', $this->payload($this->client)['status']);
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
        self::assertSame('rejected', $this->payload($this->client)['status']);
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
        self::assertSame('suspended', $this->payload($this->client)['status']);
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
        $payload = $this->payload($this->client);
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
        self::assertSame('not_found', $this->payload($this->client)['type']);
        self::assertSame('User not found.', $this->payload($this->client)['detail']);
    }

    public function testAdminCannotSuspendThemselves(): void
    {
        $admin = $this->admin();

        $this->call('POST', self::LIST . '/' . $admin->requireId() . '/suspend', $this->tokenFor($admin));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('validation_error', $this->payload($this->client)['type']);
    }

    public function testAdminCannotRejectThemselves(): void
    {
        $admin = $this->admin();

        $this->call('POST', self::LIST . '/' . $admin->requireId() . '/reject', $this->tokenFor($admin));

        self::assertResponseStatusCodeSame(422);
    }

    public function testAnAdminDeletesAnotherAccount(): void
    {
        $factory = $this->factory();
        $admin = $factory->create('admin-del@example.com', roles: ['ROLE_ADMIN']);
        $target = $factory->create('victim@example.com');
        $targetId = $target->requireId();

        $this->client->request('DELETE', self::LIST . '/' . $targetId, server: $this->bearerFor($admin));

        self::assertResponseStatusCodeSame(204);
    }

    public function testAnAdminCannotDeleteThemselvesThroughTheAdminApi(): void
    {
        $admin = $this->factory()->create('self-del@example.com', roles: ['ROLE_ADMIN']);
        $this->factory()->create('spare-admin@example.com', roles: ['ROLE_ADMIN']);

        $this->client->request('DELETE', self::LIST . '/' . $admin->getId(), server: $this->bearerFor($admin));

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

        $this->client->request('DELETE', self::LIST . '/' . $deputy->getId(), server: $this->bearerFor($soleAdmin));
        self::assertResponseStatusCodeSame(204);

        $this->client->request('DELETE', self::LIST . '/' . $soleAdmin->getId(), server: $this->bearer($deputyToken));
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

        $this->client->request('DELETE', self::LIST . '/' . $suspended->getId(), server: $this->bearerFor($caller));

        self::assertResponseStatusCodeSame(409);
    }

    public function testANonAdminCannotDeleteAnAccount(): void
    {
        $factory = $this->factory();
        $factory->create('an-admin@example.com', roles: ['ROLE_ADMIN']);
        $plainUser = $factory->create('plain@example.com');
        $target = $factory->create('other-target@example.com');

        $this->client->request('DELETE', self::LIST . '/' . $target->getId(), server: $this->bearerFor($plainUser));

        self::assertResponseStatusCodeSame(403);
    }
}
