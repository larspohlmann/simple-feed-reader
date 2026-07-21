<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\User;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Service\Auth\AltchaService;
use App\Tests\Support\AltchaSolver;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The composition test: register -> verify -> approve -> login -> /api/me, and
 * then suspend -> reinstate, driven entirely over HTTP.
 *
 * Every other test in this suite pins one seam in isolation, usually by seeding
 * the precondition it needs with UserFactory or by minting a JWT straight from
 * the token manager. That is the right shape for a unit of behaviour and the
 * wrong shape for the question asked here, which is whether the fifteen pieces
 * actually line up end to end. So nothing is seeded except the admin (who has
 * no signup path by design), and no state is nudged into place between steps:
 * each step's precondition is whatever the previous HTTP request left behind in
 * the database.
 *
 * The one shortcut is the verification token. It exists in exactly two places -
 * hashed in the database, and in plaintext inside the mail - and the test has
 * no inbox, so it is scraped out of the sent message. That is still the token
 * the register request itself produced, which is the property that matters;
 * this follows RegistrationTest::tokenFromMail() rather than inventing a
 * second approach.
 *
 * Runtime note: each journey solves one real ALTCHA challenge (~60 ms). That is
 * the proof-of-work doing its job, not a hung test.
 */
final class AuthJourneyTest extends WebTestCase
{
    private const EMAIL = 'journey@example.com';
    private const PASSWORD = 'a-perfectly-fine-passphrase';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = self::createClient();

        // Both the /register limiter and the firewall's login_throttling keep
        // their counters in a FILESYSTEM pool, which outlives the kernel reboot
        // between requests and the end of the run itself. This journey spends
        // budget from both, so without a clear it passes on a fresh checkout
        // and 429s on the second `composer test`. Same precedent as
        // RegistrationTest and LoginTest.
        $this->rateLimiterCache()->clear();
    }

    // -- Journeys ---------------------------------------------------------

    /**
     * Signup to authenticated request, with both gates in the way.
     *
     * The two 403s are the point of the double opt-in: the account is real and
     * the password is correct from step 1 onwards, so the only thing keeping
     * the user out is status - first their own unconfirmed address, then the
     * admin queue. Asserting the problem `type` rather than the bare code is
     * what distinguishes "refused because pending" from "refused for some other
     * reason that happens to be a 403 too".
     */
    public function testAJourneyFromSignupToAnAuthenticatedRequest(): void
    {
        $token = $this->onboardThroughHttp();

        $this->get('/api/me', $token);

        self::assertResponseIsSuccessful();
        self::assertSame(self::EMAIL, $this->payload()['email']);
    }

    /**
     * The revocation story, which has only ever been checked in pieces.
     *
     * There are no refresh tokens and no blocklist, so suspension has to bite
     * purely by the firewall re-reading the user on the next request. Both
     * halves are driven by the admin HTTP endpoints: mutating the entity and
     * flushing would leave the same object in the identity map and the
     * assertion would hold even if nothing were ever reloaded.
     *
     * The reinstatement half also pins the silence. `approve` is the only route
     * back from suspended, so it must work - but this user never sat in the
     * queue, and mailing them "your account has been approved" would be a lie
     * about an event that did not happen.
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

    // -- The shared arc ---------------------------------------------------

    /**
     * Steps 1-6 of the journey, asserted as it goes; returns the JWT the final
     * login handed back.
     *
     * Shared because journey two starts where journey one ends, and reaching
     * "active user holding a token they obtained by logging in" any other way
     * would mean seeding the state this is supposed to be proving.
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

    // -- HTTP helpers -----------------------------------------------------

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

    // -- Reading the world ------------------------------------------------

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
        return (int) $this->currentUser()->getId();
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

    // -- Fixtures that have no HTTP path ----------------------------------

    /**
     * The one seeded actor. Admins are provisioned out of band on purpose -
     * there is no endpoint that grants ROLE_ADMIN - so there is no HTTP route
     * to create one, and minting the admin's own token directly keeps the
     * journey's login budget for the user under test.
     */
    private function adminToken(): string
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        /** @var UserRepository $users */
        $users = self::getContainer()->get(UserRepository::class);

        $admin = $users->findOneByEmail('journey-admin@example.com')
            ?? (new UserFactory($em, $hasher))->create('journey-admin@example.com', roles: ['ROLE_ADMIN']);

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
