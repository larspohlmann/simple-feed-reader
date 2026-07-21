<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Enum\UserStatus;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class LoginTest extends WebTestCase
{
    /**
     * login_throttling persists attempt counters in a filesystem cache pool, so
     * they outlive both the test and the whole run. Left alone, the per-IP
     * global limiter saturates and every later login test gets a 429 -
     * order-dependent, history-dependent, and green only on a clean checkout.
     */
    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();
        /** @var CacheItemPoolInterface $rateLimiterCache */
        $rateLimiterCache = self::getContainer()->get('test.cache.rate_limiter');
        $rateLimiterCache->clear();
        self::ensureKernelShutdown();
    }

    private function factory(): UserFactory
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        return new UserFactory($em, $hasher);
    }

    private function login(KernelBrowser $client, string $email, string $password): void
    {
        $client->request(
            'POST',
            '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['email' => $email, 'password' => $password]),
        );
    }

    /**
     * json_decode yields a plain array; PHPStan cannot narrow the key type from
     * assertIsArray alone, so the annotation stays honest about that.
     *
     * @return array<mixed>
     */
    private function payload(KernelBrowser $client): array
    {
        $decoded = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    public function testActiveUserReceivesAToken(): void
    {
        $client = self::createClient();
        $this->factory()->create('active@example.com');

        $this->login($client, 'active@example.com', 'correct-horse-battery');

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client);
        self::assertArrayHasKey('token', $payload);
        self::assertIsString($payload['token']);
        self::assertCount(3, explode('.', $payload['token']));
    }

    public function testWrongPasswordIs401ProblemJson(): void
    {
        $client = self::createClient();
        $this->factory()->create('active@example.com');

        $this->login($client, 'active@example.com', 'wrong-password');

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertSame('invalid_credentials', $this->payload($client)['type']);
    }

    public function testUnknownEmailIs401WithTheSameShape(): void
    {
        $client = self::createClient();

        $this->login($client, 'nobody@example.com', 'whatever');

        self::assertResponseStatusCodeSame(401);
        self::assertSame('invalid_credentials', $this->payload($client)['type']);
    }

    public function testPendingApprovalIs403AndNamesTheStatus(): void
    {
        $client = self::createClient();
        $this->factory()->create('pending@example.com', status: UserStatus::PendingApproval);

        $this->login($client, 'pending@example.com', 'correct-horse-battery');

        self::assertResponseStatusCodeSame(403);
        $payload = $this->payload($client);
        self::assertSame('account_not_active', $payload['type']);
        self::assertSame('pending_approval', $payload['accountStatus']);
    }

    public function testSuspendedIs403(): void
    {
        $client = self::createClient();
        $this->factory()->create('suspended@example.com', status: UserStatus::Suspended);

        $this->login($client, 'suspended@example.com', 'correct-horse-battery');

        self::assertResponseStatusCodeSame(403);
        self::assertSame('suspended', $this->payload($client)['accountStatus']);
    }

    /**
     * Brute-force defence is invisible when it silently is not wired: the
     * config would still look correct. This pins that the 6th attempt inside
     * the window is actually refused, with the Retry-After the client needs.
     */
    public function testSixthFailedAttemptIsThrottled(): void
    {
        $client = self::createClient();
        $this->factory()->create('throttled@example.com');

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->login($client, 'throttled@example.com', 'wrong-password');
            self::assertResponseStatusCodeSame(401, sprintf('attempt %d should still be 401', $attempt));
        }

        $this->login($client, 'throttled@example.com', 'wrong-password');

        self::assertResponseStatusCodeSame(429);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertResponseHeaderSame('Retry-After', '900');
        self::assertSame('rate_limited', $this->payload($client)['type']);
    }

    /**
     * The throttle is keyed on the identifier as well as the IP, so a correct
     * password must not be accepted once that identifier is locked out -
     * otherwise the limiter would only be delaying an attacker who already
     * knows the password.
     */
    public function testThrottleAlsoBlocksTheCorrectPassword(): void
    {
        $client = self::createClient();
        $this->factory()->create('locked@example.com');

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->login($client, 'locked@example.com', 'wrong-password');
        }

        $this->login($client, 'locked@example.com', 'correct-horse-battery');

        self::assertResponseStatusCodeSame(429);
    }

    /**
     * KNOWN ENUMERATION LEAK - this test documents current behaviour, it does
     * not endorse it.
     *
     * UserCheckerListener::preCheckCredentials runs at priority 256 and
     * CheckCredentialsListener at priority 0, so the status check happens
     * BEFORE the password is verified. A non-active account therefore answers
     * 403 with its status to anyone who guesses the address, no password
     * required - an account-enumeration oracle.
     *
     * It cannot be fixed by moving the check to checkPostAuth: the `api`
     * firewall registers only preCheckCredentials, so JWT-request revocation
     * would stop working. A fix needs a login-firewall-specific check that runs
     * after CheckCredentialsListener. Tracked separately; when it lands, this
     * test should flip to expecting 401.
     */
    public function testSuspendedWithWrongPasswordLeaksStatusBeforePasswordCheck(): void
    {
        $client = self::createClient();
        $this->factory()->create('leaky@example.com', status: UserStatus::Suspended);

        $this->login($client, 'leaky@example.com', 'definitely-not-the-password');

        self::assertResponseStatusCodeSame(403);
        self::assertSame('account_not_active', $this->payload($client)['type']);
    }
}
