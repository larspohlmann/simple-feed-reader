<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\User;
use App\Enum\UserStatus;
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
                ['id', 'email', 'status', 'roles', 'createdAt', 'approvedAt'],
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
     * Documents current behaviour rather than blessing it: approve carries no
     * idempotence guard, so a second click re-sends the "you're in" mail. See
     * the review notes on this task.
     */
    public function testApprovingAnAlreadyActiveUserMailsAgain(): void
    {
        $admin = $this->admin();
        $target = $this->factory()->create('already@example.com', status: UserStatus::Active);
        $uri = self::LIST . '/' . (int) $target->getId() . '/approve';

        $this->call('POST', $uri, $this->tokenFor($admin));
        self::assertEmailCount(1);

        $this->call('POST', $uri, $this->tokenFor($admin));
        self::assertResponseIsSuccessful();
        self::assertEmailCount(
            1,
            message: 'the kernel reboots between requests, so this counts the second call alone',
        );
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

    public function testAdminCannotRejectThemselves(): void
    {
        $admin = $this->admin();

        $this->call('POST', self::LIST . '/' . (int) $admin->getId() . '/reject', $this->tokenFor($admin));

        self::assertResponseStatusCodeSame(422);
    }
}
