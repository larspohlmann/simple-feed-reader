<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\User;
use App\Enum\UserStatus;
use App\EventListener\AddUserIdClaimOnTokenIssueListener;
use App\Security\PasswordWorkEqualizer;
use App\Tests\Support\HashCountingWork;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class LoginTest extends WebTestCase
{
    /** login_throttling counters live in a filesystem pool that outlives the run; clear them or later tests 429. */
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
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        return new UserFactory($entityManager, $hasher);
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

    /** @return array<mixed> */
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

    public function testALoginTokenCarriesTheAccountId(): void
    {
        $client = self::createClient();
        $this->factory()->create('claim-user-one@example.com');
        $second = $this->factory()->create('claim-user-two@example.com');

        $this->login($client, 'claim-user-two@example.com', 'correct-horse-battery');

        self::assertResponseIsSuccessful();
        $token = $this->payload($client)['token'];
        self::assertIsString($token);
        /** @var JWTTokenManagerInterface $tokens */
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);

        self::assertSame($second->getId(), $tokens->parse($token)[AddUserIdClaimOnTokenIssueListener::CLAIM] ?? null);
    }

    /**
     * Addresses are stored lowercase, so the provider must normalise the submission too, or a capitalised address
     * would 401 forever with the right password. Both directions.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function casingProvider(): iterable
    {
        yield 'registered mixed, typed lower' => ['MixedCase@Example.com', 'mixedcase@example.com'];
        yield 'registered lower, typed upper' => ['plain@example.com', 'PLAIN@EXAMPLE.COM'];
        yield 'registered lower, typed mixed' => ['plain2@example.com', 'Plain2@Example.Com'];
    }

    #[DataProvider('casingProvider')]
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

    public function testExpiredTrialLoginIs403AndNamesSuspended(): void
    {
        $client = self::createClient();
        $this->factory()->create(
            'trial-login@example.com',
            trialEndsAt: new \DateTimeImmutable('-1 day'),
        );

        $this->login($client, 'trial-login@example.com', 'correct-horse-battery');

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

    /** The throttle keys on the identifier too, so a locked-out identifier refuses even the correct password. */
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
     * Padding variants an attacker can mint indefinitely. trim() strips six
     * bytes, so the supply of "different" spellings of one address is
     * unbounded; five distinct ones are enough to prove the bucket is shared.
     *
     * @return list<string>
     */
    private static function paddedVariants(string $email): array
    {
        return [
            ' ' . $email,
            $email . ' ',
            "\t" . $email,
            "\n" . $email,
            "  \t" . $email . " \n",
        ];
    }

    /**
     * DefaultLoginRateLimiter keys on the raw lower-cased identifier while User::normalizeEmail() also trims: five
     * failures over five paddings must exhaust the one bucket the unpadded address draws from.
     */
    public function testPaddedIdentifiersShareOneThrottleBucket(): void
    {
        $client = self::createClient();
        $this->factory()->create('padded@example.com');

        foreach (self::paddedVariants('padded@example.com') as $index => $variant) {
            $this->login($client, $variant, 'wrong-password');
            self::assertResponseStatusCodeSame(401, sprintf('padded attempt %d should still be 401', $index + 1));
        }

        // Sixth attempt, unpadded: the budget was spent by the padded ones.
        $this->login($client, 'padded@example.com', 'wrong-password');

        self::assertResponseStatusCodeSame(429);
        self::assertSame('rate_limited', $this->payload($client)['type']);
    }

    /**
     * The mirror image: the unpadded address spends the budget, and a padded
     * spelling must not buy a fresh one.
     */
    public function testPaddedIdentifierCannotEscapeAnExhaustedBucket(): void
    {
        $client = self::createClient();
        $this->factory()->create('escape@example.com');

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->login($client, 'escape@example.com', 'wrong-password');
            self::assertResponseStatusCodeSame(401);
        }

        $this->login($client, "\t escape@example.com \n", 'wrong-password');

        self::assertResponseStatusCodeSame(429);
    }

    /**
     * Normalising the throttle key must not break login: the provider trims too, so a padded address with the right
     * password still logs in.
     */
    public function testPaddedIdentifierWithTheCorrectPasswordStillLogsIn(): void
    {
        $client = self::createClient();
        $this->factory()->create('spacey@example.com');

        $this->login($client, "  spacey@example.com \t", 'correct-horse-battery');

        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('token', $this->payload($client));
    }

    /**
     * A wrong password against a suspended account must be the ordinary 401: the status check runs post-auth, so a
     * guessed address learns nothing.
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

    /**
     * An OAuth-only account has no hash, so Symfony skips the hasher; the response must match an unknown address byte
     * for byte. The hash itself is counted in testEveryCredentialFailureCostsTheSameOneHash.
     */
    public function testAPasswordLoginAgainstAnOAuthOnlyAccountIsIndistinguishableFromAnUnknownAddress(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User('oauth-only@example.com', new \DateTimeImmutable('2026-07-01 10:00:00'));
        $user->approve(new \DateTimeImmutable('2026-07-01 10:00:00'));
        // No password hash: this account exists only through a provider.
        $entityManager->persist($user);
        $entityManager->flush();

        $bodies = [];
        foreach (['oauth-only@example.com', 'ghost@example.com'] as $email) {
            $this->login($client, $email, 'anything');
            self::assertResponseStatusCodeSame(401);
            self::assertResponseHeaderSame('content-type', 'application/problem+json');
            $bodies[] = (string) $client->getResponse()->getContent();
        }

        self::assertCount(1, array_unique($bodies));
    }

    /**
     * Counts the hashes the wired stack spends. Functional on purpose: LoginFailureHandler must recover the address
     * from a consumed body, and a direct equalize() call would stay green if it always passed null.
     */
    public function testEveryCredentialFailureCostsTheSameOneHash(): void
    {
        $client = self::createClient();
        // Without this the browser rebuilds the container after every request,
        // which would quietly restore the real equalizer and leave this test
        // measuring the first request only.
        $client->disableReboot();
        $hashes = new HashCountingWork();
        self::getContainer()->set(PasswordWorkEqualizer::class, $hashes);

        $this->factory()->create('has-password@example.com');

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $oauthOnly = new User('no-password@example.com', new \DateTimeImmutable('2026-07-01 10:00:00'));
        $oauthOnly->approve(new \DateTimeImmutable('2026-07-01 10:00:00'));
        $entityManager->persist($oauthOnly);
        $entityManager->flush();

        $spent = [];
        foreach (['unknown@example.com', 'no-password@example.com', 'has-password@example.com'] as $email) {
            $before = $hashes->calls;
            $this->login($client, $email, 'wrong-password');
            self::assertResponseStatusCodeSame(401);
            $spent[$email] = $hashes->calls - $before;
        }

        // Unknown and OAuth-only skipped the hasher, so each buys one back; the password account already verified one.
        self::assertSame(
            ['unknown@example.com' => 1, 'no-password@example.com' => 1, 'has-password@example.com' => 0],
            $spent,
        );
    }
}
