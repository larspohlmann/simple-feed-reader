<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\User;
use App\Enum\TokenPurpose;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Service\Auth\ActionTokenService;
use App\Service\Auth\AltchaService;
use App\Tests\Support\AltchaSolver;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Runtime note: every registration here solves a real ALTCHA proof-of-work
 * (~60 ms). This file is deliberately slower than the rest of the suite.
 */
final class RegistrationTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = self::createClient();

        // The per-IP limiters on /register and /password-reset-request store
        // their state in a FILESYSTEM pool, which survives the kernel reboot
        // between requests *and* the end of the run. Without this the budget is
        // already spent by the second `composer test` and the suite goes red
        // for reasons that have nothing to do with the code.
        $this->rateLimiterCache()->clear();
    }

    private function rateLimiterCache(): CacheItemPoolInterface
    {
        /** @var CacheItemPoolInterface $cache */
        $cache = self::getContainer()->get('test.cache.rate_limiter');

        return $cache;
    }

    private function altchaPayload(): string
    {
        /** @var AltchaService $altcha */
        $altcha = self::getContainer()->get(AltchaService::class);

        return AltchaSolver::solve($altcha);
    }

    /** @param array<string, string> $overrides */
    private function register(array $overrides = []): void
    {
        $body = $overrides + [
            'email' => 'newcomer@example.com',
            'password' => 'correct-horse-battery',
            // Solved lazily: an override means the test does not want to pay
            // 60 ms of hashing for a payload it is about to throw away.
            'altcha' => null,
        ];
        $body['altcha'] ??= $this->altchaPayload();

        $this->client->request(
            'POST',
            '/api/auth/register',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode($body),
        );
    }

    private function post(string $path, mixed $body): void
    {
        $this->client->request(
            'POST',
            $path,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode($body),
        );
    }

    /** @return array<mixed> */
    private function payload(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function users(): UserRepository
    {
        /** @var UserRepository $repository */
        $repository = self::getContainer()->get(UserRepository::class);

        return $repository;
    }

    private function factory(): UserFactory
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        return new UserFactory($em, $hasher);
    }

    private function tokens(): ActionTokenService
    {
        /** @var ActionTokenService $tokens */
        $tokens = self::getContainer()->get(ActionTokenService::class);

        return $tokens;
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

    public function testAltchaChallengeIsPublicAndWellFormed(): void
    {
        $this->client->request('GET', '/api/auth/altcha-challenge');

        self::assertResponseIsSuccessful();
        $payload = $this->payload();
        self::assertArrayHasKey('challenge', $payload);
        self::assertArrayHasKey('maxnumber', $payload);
        self::assertArrayHasKey('salt', $payload);
        self::assertArrayHasKey('signature', $payload);
    }

    public function testRegisterCreatesAPendingUserAndSendsOneMail(): void
    {
        $this->register();

        self::assertResponseStatusCodeSame(202);
        self::assertSame(['status' => 'pending_verification'], $this->payload());

        $user = $this->users()->findOneByEmail('newcomer@example.com');
        self::assertInstanceOf(User::class, $user);
        self::assertSame(UserStatus::PendingVerification, $user->getStatus());
        self::assertNotNull($user->getPasswordHash());

        self::assertEmailCount(1);
    }

    /**
     * Without the challenge check the endpoint is a free mail cannon. Assert
     * both halves: the refusal, and that nothing was written on the way to it.
     */
    public function testUnsolvedAltchaIsRejectedAndCreatesNoUser(): void
    {
        $this->register(['altcha' => 'garbage']);

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        $payload = $this->payload();
        self::assertSame('validation_error', $payload['type']);
        self::assertArrayHasKey('errors', $payload);
        self::assertIsArray($payload['errors']);
        self::assertArrayHasKey('altcha', $payload['errors']);

        self::assertNull($this->users()->findOneByEmail('newcomer@example.com'));
        self::assertEmailCount(0);
    }

    public function testInvalidEmailAndShortPasswordAreBothReported(): void
    {
        $this->register(['email' => 'not-an-email', 'password' => 'short', 'altcha' => 'unused']);

        self::assertResponseStatusCodeSame(422);
        $payload = $this->payload();
        self::assertSame('validation_error', $payload['type']);
        self::assertIsArray($payload['errors']);
        self::assertArrayHasKey('email', $payload['errors']);
        self::assertArrayHasKey('password', $payload['errors']);
    }

    /**
     * The enumeration guarantee, checked on the wire rather than in the
     * service: status, headers and body must be indistinguishable between a
     * fresh address and one that is already taken. Date varies by definition
     * and is excluded.
     */
    public function testDuplicateRegistrationIsByteIdentical(): void
    {
        $this->register();
        self::assertResponseStatusCodeSame(202);
        $first = $this->client->getResponse();
        $firstStatus = $first->getStatusCode();
        $firstBody = (string) $first->getContent();
        $firstHeaders = $first->headers->all();

        $this->register();
        $second = $this->client->getResponse();

        self::assertSame($firstStatus, $second->getStatusCode());
        self::assertSame($firstBody, (string) $second->getContent());

        unset($firstHeaders['date']);
        $secondHeaders = $second->headers->all();
        unset($secondHeaders['date']);
        self::assertSame($firstHeaders, $secondHeaders);

        self::assertCount(1, $this->users()->findBy(['email' => 'newcomer@example.com']));
    }

    /**
     * The second registration must not send a second verification mail either -
     * an unexpected mail landing in an existing user's inbox is an enumeration
     * oracle with a delivery mechanism attached.
     *
     * The client reboots the kernel between requests, so the mailer event log
     * only ever holds the most recent request's messages. That is exactly the
     * scope wanted here: zero mails attributable to the duplicate attempt.
     */
    public function testDuplicateRegistrationSendsNoSecondMail(): void
    {
        $this->register();
        self::assertEmailCount(1, message: 'the first registration should mail');

        $this->register();
        self::assertEmailCount(0, message: 'the duplicate registration must mail nothing');
    }

    public function testVerificationMovesTheUserToPendingApproval(): void
    {
        $this->register();
        $token = $this->tokenFromMail();

        $this->post('/api/auth/verify-email', ['token' => $token]);

        self::assertResponseIsSuccessful();
        self::assertSame(['status' => 'pending_approval'], $this->payload());

        $user = $this->users()->findOneByEmail('newcomer@example.com');
        self::assertInstanceOf(User::class, $user);
        self::assertSame(UserStatus::PendingApproval, $user->getStatus());
    }

    /**
     * A verification link is single-use. The second click - the one the SPA
     * causes by reloading the page - must fail closed, not silently succeed.
     */
    public function testAVerificationTokenCannotBeUsedTwice(): void
    {
        $this->register();
        $token = $this->tokenFromMail();

        $this->post('/api/auth/verify-email', ['token' => $token]);
        self::assertResponseIsSuccessful();

        $this->post('/api/auth/verify-email', ['token' => $token]);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_token', $this->payload()['type']);
    }

    public function testUnknownTokenIsRejected(): void
    {
        $this->post('/api/auth/verify-email', ['token' => str_repeat('a', 64)]);

        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertSame('invalid_token', $this->payload()['type']);
    }

    /**
     * A token longer than the column and the DTO allow must be refused by
     * validation, before it reaches the service or the database.
     */
    public function testAnOverlongTokenIsRejectedByValidation(): void
    {
        $this->post('/api/auth/verify-email', ['token' => str_repeat('a', 129)]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('validation_error', $this->payload()['type']);
    }

    /**
     * Verifying the address is only the first of two gates. If this ever
     * returned a token, the manual-approval step would be decorative.
     */
    public function testVerifiedButUnapprovedUserStillCannotLogIn(): void
    {
        $this->register();
        $this->post('/api/auth/verify-email', ['token' => $this->tokenFromMail()]);

        $this->post('/api/auth/login', [
            'email' => 'newcomer@example.com',
            'password' => 'correct-horse-battery',
        ]);

        self::assertResponseStatusCodeSame(403);
        $payload = $this->payload();
        self::assertSame('account_not_active', $payload['type']);
        self::assertSame('pending_approval', $payload['accountStatus']);
    }

    /**
     * Purpose is part of what a token authorises. A password-reset token that
     * could also confirm an address would let an attacker who intercepted one
     * mail complete a flow the user never started.
     */
    public function testAPasswordResetTokenCannotVerifyAnEmail(): void
    {
        $user = $this->factory()->create('crossuse@example.com', status: UserStatus::PendingVerification);
        $token = $this->tokens()->issue($user, TokenPurpose::ResetPassword);

        $this->post('/api/auth/verify-email', ['token' => $token]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(UserStatus::PendingVerification, $user->getStatus());
    }

    /**
     * ALTCHA sizes the cost of abuse; it does not cap it. At the measured
     * ~60 ms per solved challenge an unlimited endpoint is tens of thousands of
     * outbound mails a day from a single host. This pins that the cap exists,
     * and that the client is told when to come back.
     */
    public function testSixthRegistrationFromOneIpIsThrottled(): void
    {
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->register();
            self::assertResponseStatusCodeSame(202, sprintf('attempt %d should still be accepted', $attempt));
        }

        $this->register();

        self::assertResponseStatusCodeSame(429);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertSame('rate_limited', $this->payload()['type']);

        $retryAfter = $this->client->getResponse()->headers->get('Retry-After');
        self::assertNotNull($retryAfter);
        self::assertGreaterThan(0, (int) $retryAfter);
        self::assertLessThanOrEqual(900, (int) $retryAfter);
    }

    /**
     * Separate budgets: a user who burned through registration attempts must
     * still be able to recover an account they already own.
     */
    public function testRegistrationAndPasswordResetHaveIndependentBudgets(): void
    {
        $this->factory()->create('recoverme@example.com');

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->register();
        }
        $this->register();
        self::assertResponseStatusCodeSame(429, 'registration budget should now be spent');

        $this->post('/api/auth/password-reset-request', [
            'email' => 'recoverme@example.com',
            'altcha' => $this->altchaPayload(),
        ]);

        self::assertResponseIsSuccessful();
    }

    /**
     * Re-verifying must not demote an account an admin has already approved
     * back into the approval queue.
     */
    public function testVerifyingAnActiveAccountDoesNotDemoteIt(): void
    {
        $user = $this->factory()->create('approved@example.com', status: UserStatus::Active);
        $token = $this->tokens()->issue($user, TokenPurpose::VerifyEmail);

        $this->post('/api/auth/verify-email', ['token' => $token]);

        self::assertResponseIsSuccessful();
        self::assertSame(UserStatus::Active, $user->getStatus());
    }
}
