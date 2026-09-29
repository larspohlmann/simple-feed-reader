<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\User;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Service\Auth\AltchaService;
use App\Tests\Support\AltchaSolver;
use App\Tests\Support\EnablesMailInTests;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Register, verify, approve, log in, /api/me, then suspend and reinstate, over HTTP only: nothing but the admin is
 * seeded, and each step starts from what the previous request left in the database. The verification token is read
 * from the sent mail, its only plaintext copy. Each journey solves one real ALTCHA challenge (~60 ms).
 */
final class AuthJourneyTest extends WebTestCase
{
    use EnablesMailInTests;

    private const EMAIL = 'journey@example.com';
    private const PASSWORD = 'a-perfectly-fine-passphrase';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = self::createClient();
        $this->seedEnabledMailInstance();

        // The /register limiter and login_throttling keep counters in a filesystem pool that outlives the run; this
        // journey spends both budgets, so without a clear the second run 429s.
        $this->rateLimiterCache()->clear();
    }

    /**
     * The two 403s are the double opt-in: the password is right from step 1, so only the status keeps the user out.
     * Asserting the problem `type`, not just 403, tells "pending" from any other refusal.
     */
    public function testAJourneyFromSignupToAnAuthenticatedRequest(): void
    {
        $token = $this->onboardThroughHttp();

        $this->get('/api/me', $token);

        self::assertResponseIsSuccessful();
        self::assertSame(self::EMAIL, $this->payload()['email']);
    }

    /**
     * Suspension bites only because the firewall re-reads the user. Both halves go through the admin endpoints: a
     * mutated, flushed entity would pass without any reload. Reinstating through `approve` sends no "approved" mail.
     */
    public function testASuspendedUsersLiveTokenDiesAndReinstatementIsSilent(): void
    {
        $userToken = $this->onboardThroughHttp();
        $userId = $this->userId();
        $adminToken = $this->adminToken();

        // The token works right up until the moment it does not.
        $this->get('/api/me', $userToken);
        self::assertResponseIsSuccessful();

        $this->post('/api/admin/users/' . $userId . '/suspend', null, $adminToken);
        self::assertResponseIsSuccessful();
        self::assertSame('suspended', $this->payload()['status']);
        self::assertEmailCount(0, message: 'suspension is not announced to the suspended user');

        // Same token, still cryptographically valid and nowhere near expiry.
        // The 401 can only come from the user having been re-read from the row
        // the suspend request wrote.
        $this->get('/api/me', $userToken);
        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertSame('unauthorized', $this->payload()['type']);

        // ...and a fresh login is refused too, so this is revocation rather
        // than a quirk of how that one JWT was validated.
        $this->login();
        self::assertResponseStatusCodeSame(403);
        self::assertSame('account_not_active', $this->payload()['type']);
        self::assertSame('suspended', $this->payload()['accountStatus']);

        // Reinstate.
        $this->post('/api/admin/users/' . $userId . '/approve', null, $adminToken);
        self::assertResponseIsSuccessful();
        self::assertSame('active', $this->payload()['status']);
        self::assertEmailCount(0, message: 'reinstatement is not a first-time approval announcement');

        $this->login();
        self::assertResponseIsSuccessful();
        $this->get('/api/me', $this->tokenFromLoginResponse());
        self::assertResponseIsSuccessful();
        self::assertSame(self::EMAIL, $this->payload()['email']);
    }

    /**
     * Steps 1–6, asserted as it goes; returns the JWT the final login handed back. Both journeys start here, so the
     * active user with a real login token is never seeded.
     */
    private function onboardThroughHttp(): string
    {
        // 1. A challenge, solved, spent on a registration.
        $this->client->request('GET', '/api/auth/altcha-challenge');
        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('challenge', $this->payload());

        $this->post('/api/auth/register', [
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
            'altcha' => $this->solvedAltcha(),
        ]);
        self::assertResponseStatusCodeSame(202);
        self::assertSame(['status' => 'pending_verification'], $this->payload());
        self::assertSame(UserStatus::PendingVerification, $this->currentStatus());

        // The token only exists because that request created it.
        $verificationToken = $this->tokenFromMail();

        // 2. Correct password, wrong moment: the address is unconfirmed.
        $this->login();
        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertSame('account_not_active', $this->payload()['type']);
        self::assertSame('pending_verification', $this->payload()['accountStatus']);

        // 3. Confirm the address.
        $this->post('/api/auth/verify-email', ['token' => $verificationToken]);
        self::assertResponseIsSuccessful();
        self::assertSame(['status' => 'pending_approval'], $this->payload());
        self::assertSame(UserStatus::PendingApproval, $this->currentStatus());

        // 4. Still out: confirming an address is the first of two gates, and if
        // this ever returned a token the approval queue would be decorative.
        $this->login();
        self::assertResponseStatusCodeSame(403);
        self::assertSame('account_not_active', $this->payload()['type']);
        self::assertSame('pending_approval', $this->payload()['accountStatus']);

        // 5. An admin lets them in. The user id comes from the row registration
        // wrote, so this addresses the account the journey actually created.
        $this->post('/api/admin/users/' . $this->userId() . '/approve', null, $this->adminToken());
        self::assertResponseIsSuccessful();
        self::assertSame('active', $this->payload()['status']);
        self::assertEmailCount(1, message: 'clearing the queue is announced exactly once');
        self::assertSame(UserStatus::Active, $this->currentStatus());

        // 6. Same credentials as step 2, now accepted. Nothing about the
        // request changed; only the status did.
        $this->login();
        self::assertResponseIsSuccessful();

        return $this->tokenFromLoginResponse();
    }

    private function post(string $path, mixed $body, ?string $token = null): void
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if (null !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }

        $this->client->request('POST', $path, server: $server, content: (string) json_encode($body));
    }

    private function get(string $path, string $token): void
    {
        $this->client->request('GET', $path, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
    }

    /** Always the same credentials, so a changed outcome can only mean changed state. */
    private function login(): void
    {
        $this->post('/api/auth/login', ['email' => self::EMAIL, 'password' => self::PASSWORD]);
    }

    /** @return array<mixed> */
    private function payload(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function tokenFromLoginResponse(): string
    {
        $token = $this->payload()['token'] ?? null;
        self::assertIsString($token);
        self::assertCount(3, explode('.', $token), 'a JWT has three dot-separated parts');

        return $token;
    }

    /**
     * Re-reads through the CURRENT kernel's entity manager, which is a fresh
     * one after each request's reboot - so this observes the database, not a
     * cached object graph.
     */
    private function currentUser(): User
    {
        /** @var UserRepository $users */
        $users = self::getContainer()->get(UserRepository::class);
        $user = $users->findOneByEmail(self::EMAIL);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function currentStatus(): UserStatus
    {
        return $this->currentUser()->getStatus();
    }

    private function userId(): int
    {
        return $this->currentUser()->requireId();
    }

    /** Pulls the plaintext token out of the mail, the only place it exists. */
    private function tokenFromMail(): string
    {
        $message = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $message);

        if (1 !== preg_match('/token=([0-9a-f]{64})/', (string) $message->getTextBody(), $matches)) {
            self::fail('the verification mail should carry a 64-char token');
        }

        return $matches[1];
    }

    /**
     * The one seeded actor: nothing grants ROLE_ADMIN over HTTP. Minting its token directly keeps the journey's login
     * budget for the user under test.
     */
    private function adminToken(): string
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        /** @var UserRepository $users */
        $users = self::getContainer()->get(UserRepository::class);

        $admin = $users->findOneByEmail('journey-admin@example.com')
            ?? (new UserFactory($entityManager, $hasher))->create('journey-admin@example.com', roles: ['ROLE_ADMIN']);

        /** @var JWTTokenManagerInterface $jwt */
        $jwt = self::getContainer()->get(JWTTokenManagerInterface::class);

        return $jwt->create($admin);
    }

    private function solvedAltcha(): string
    {
        /** @var AltchaService $altcha */
        $altcha = self::getContainer()->get(AltchaService::class);

        return AltchaSolver::solve($altcha);
    }

    private function rateLimiterCache(): CacheItemPoolInterface
    {
        /** @var CacheItemPoolInterface $cache */
        $cache = self::getContainer()->get('test.cache.rate_limiter');

        return $cache;
    }
}
