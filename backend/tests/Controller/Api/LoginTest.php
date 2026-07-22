<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\User;
use App\Enum\UserStatus;
use App\Security\PasswordWorkEqualizer;
use App\Tests\Support\HashCountingWork;
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
     * The bypass this closes. User::normalizeEmail() trims, so " bob@x" and
     * "bob@x" authenticate as one account — but Symfony's
     * DefaultLoginRateLimiter keys the bucket on mb_strtolower() of the RAW
     * submitted identifier and never trims. Every fresh padding gets a fresh
     * budget of five, and the per-identifier throttle stops existing.
     *
     * Five failures spread across five distinct paddings must exhaust the ONE
     * bucket that the unpadded address also draws from.
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
     * Normalising the throttle key must not break authentication itself. A
     * padded identifier resolves to a real account (the user provider trims
     * too), so a padded submission with the CORRECT password still logs in —
     * this is what stops the fix from becoming a lockout for anyone whose
     * client appends a stray space.
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

    /**
     * An account that exists only through a provider has no password hash at
     * all. Symfony's CheckCredentialsListener returns before it reaches the
     * hasher for those, so this request skips the argon2 every other login
     * pays for — see App\Security\LoginTimingEqualizer, which buys it back.
     *
     * Asserted here as byte equality with an unknown address rather than as a
     * duration: the response is the part a functional test can pin down, and
     * the hash decision is covered by LoginTimingEqualizerTest.
     */
    public function testAPasswordLoginAgainstAnOAuthOnlyAccountIsIndistinguishableFromAnUnknownAddress(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User('oauth-only@example.com', new \DateTimeImmutable('2026-07-01 10:00:00'));
        $user->setStatus(UserStatus::Active);
        // No password hash: this account exists only through a provider.
        $em->persist($user);
        $em->flush();

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
     * The response bytes above would look identical even if the equalizer never
     * fired, so this counts the work the real, wired-up stack actually spends.
     *
     * It is a functional test rather than a call to equalize(): the whole
     * mechanism depends on LoginFailureHandler recovering the submitted address
     * from a request body the authenticator has already consumed, and a test
     * that invokes the equalizer directly asserts that away — it would stay
     * green with a handler that passed null every time, which would silently
     * hash on every failure and lose the wrong-password case entirely.
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

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $oauthOnly = new User('no-password@example.com', new \DateTimeImmutable('2026-07-01 10:00:00'));
        $oauthOnly->setStatus(UserStatus::Active);
        $em->persist($oauthOnly);
        $em->flush();

        $spent = [];
        foreach (['unknown@example.com', 'no-password@example.com', 'has-password@example.com'] as $email) {
            $before = $hashes->calls;
            $this->login($client, $email, 'wrong-password');
            self::assertResponseStatusCodeSame(401);
            $spent[$email] = $hashes->calls - $before;
        }

        // Unknown and OAuth-only skipped the hasher inside the security layer,
        // so the equalizer buys one hash back for each. The password account
        // already paid for a real verify, so it buys none — one hash of work
        // on all three paths, which is the whole point.
        self::assertSame(
            ['unknown@example.com' => 1, 'no-password@example.com' => 1, 'has-password@example.com' => 0],
            $spent,
        );
    }
}
