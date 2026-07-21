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

    /**
     * The trap that comes with normalising on write: addresses are stored
     * lowercase, so if the security provider queried the raw submission a user
     * whose client capitalises the first letter would get a bare 401 forever,
     * with the password they typed being correct. Both directions are checked -
     * registering mixed and typing lower, and the reverse.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function casingProvider(): iterable
    {
        yield 'registered mixed, typed lower' => ['MixedCase@Example.com', 'mixedcase@example.com'];
        yield 'registered lower, typed upper' => ['plain@example.com', 'PLAIN@EXAMPLE.COM'];
        yield 'registered lower, typed mixed' => ['plain2@example.com', 'Plain2@Example.Com'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('casingProvider')]
    public function testLoginIsCaseInsensitiveOnTheEmail(string $registered, string $typed): void
    {
        $client = self::createClient();
        $this->factory()->create($registered);

        $this->login($client, $typed, 'correct-horse-battery');

        self::assertResponseIsSuccessful();
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
     * The enumeration oracle this closes: while the status check ran in
     * checkPreAuth it fired BEFORE the password was verified, so a suspended
     * account answered 403-with-status to anyone who merely guessed the
     * address. LoginUserChecker moved it to checkPostAuth. A wrong password
     * must now be indistinguishable from any other bad login.
     */
    public function testNonActiveAccountWithWrongPasswordIsIndistinguishableFrom401(): void
    {
        $client = self::createClient();
        $this->factory()->create('leaky@example.com', status: UserStatus::Suspended);

        $this->login($client, 'leaky@example.com', 'definitely-not-the-password');

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        $payload = $this->payload($client);
        self::assertSame('invalid_credentials', $payload['type']);
        self::assertArrayNotHasKey('accountStatus', $payload);
    }

    /**
     * The other half of the guarantee: a wrong password against a suspended
     * account must produce the SAME bytes as a wrong password against an active
     * one, otherwise the status still leaks through a subtler channel.
     */
    public function testWrongPasswordResponseDoesNotVaryWithAccountStatus(): void
    {
        $client = self::createClient();
        $this->factory()->create('act@example.com');
        $this->factory()->create('susp@example.com', status: UserStatus::Suspended);
        $this->factory()->create('pend@example.com', status: UserStatus::PendingApproval);

        $bodies = [];
        foreach (['act@example.com', 'susp@example.com', 'pend@example.com', 'ghost@example.com'] as $email) {
            $this->login($client, $email, 'wrong-password');
            self::assertResponseStatusCodeSame(401);
            $bodies[] = (string) $client->getResponse()->getContent();
        }

        self::assertCount(1, array_unique($bodies), 'Wrong-password responses must not vary by account status.');
    }
}
