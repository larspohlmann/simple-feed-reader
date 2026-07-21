<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\User;
use App\Enum\TokenPurpose;
use App\Enum\UserStatus;
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
 * Runtime note: every reset request here solves a real ALTCHA proof-of-work
 * (~60 ms). This file is deliberately slower than the rest of the suite.
 */
final class PasswordResetTest extends WebTestCase
{
    private const NEW_PASSWORD = 'a-brand-new-passphrase';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = self::createClient();

        // Both the per-IP limiter on /password-reset-request and the firewall's
        // login_throttling live in a FILESYSTEM pool that outlives the run. See
        // the same note in RegistrationTest and LoginTest.
        /** @var CacheItemPoolInterface $cache */
        $cache = self::getContainer()->get('test.cache.rate_limiter');
        $cache->clear();
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

    private function altchaPayload(): string
    {
        /** @var AltchaService $altcha */
        $altcha = self::getContainer()->get(AltchaService::class);

        return AltchaSolver::solve($altcha);
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

    private function requestReset(string $email, ?string $altcha = null): void
    {
        $this->post('/api/auth/password-reset-request', [
            'email' => $email,
            'altcha' => $altcha ?? $this->altchaPayload(),
        ]);
    }

    private function login(string $email, string $password): void
    {
        $this->post('/api/auth/login', ['email' => $email, 'password' => $password]);
    }

    /** @return array<mixed> */
    private function payload(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function tokenFromMail(): string
    {
        $message = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $message);

        if (1 !== preg_match('/token=([0-9a-f]{64})/', (string) $message->getTextBody(), $matches)) {
            self::fail('the reset mail should carry a 64-char token');
        }

        return $matches[1];
    }

    public function testRequestForAnActiveAccountSendsOneMail(): void
    {
        $this->factory()->create('active@example.com');

        $this->requestReset('active@example.com');

        self::assertResponseIsSuccessful();
        self::assertSame(['status' => 'sent'], $this->payload());
        self::assertEmailCount(1);
    }

    /**
     * The enumeration guarantee: an address nobody registered must produce the
     * same answer as one that exists, and must not cause any mail at all.
     */
    public function testRequestForAnUnknownAddressLooksIdenticalAndMailsNothing(): void
    {
        $this->requestReset('nobody@example.com');

        self::assertResponseIsSuccessful();
        self::assertSame('{"status":"sent"}', (string) $this->client->getResponse()->getContent());
        self::assertEmailCount(0);
    }

    /**
     * An account still waiting on an admin has no usable password, so a reset
     * mail would be both useless and a confirmation that the address is taken.
     */
    public function testRequestForAPendingApprovalAccountMailsNothing(): void
    {
        $this->factory()->create('waiting@example.com', status: UserStatus::PendingApproval);

        $this->requestReset('waiting@example.com');

        self::assertResponseIsSuccessful();
        self::assertSame(['status' => 'sent'], $this->payload());
        self::assertEmailCount(0);
    }

    /**
     * Suspended is deliberately allowed: the account may be reinstated later,
     * and locking the owner out of their own recovery flow helps nobody. The
     * status check that keeps them from signing in lives at login.
     */
    public function testSuspendedAccountsMayStillRequestAReset(): void
    {
        $this->factory()->create('suspended@example.com', status: UserStatus::Suspended);

        $this->requestReset('suspended@example.com');

        self::assertResponseIsSuccessful();
        self::assertEmailCount(1);
    }

    /**
     * Byte-for-byte comparison across all four interesting states. A difference
     * in any of them turns this endpoint into an account oracle that needs no
     * password at all.
     */
    public function testResponseDoesNotVaryWithAccountExistenceOrStatus(): void
    {
        $this->factory()->create('a-active@example.com');
        $this->factory()->create('b-pending@example.com', status: UserStatus::PendingApproval);
        $this->factory()->create('c-rejected@example.com', status: UserStatus::Rejected);

        $bodies = [];
        $statuses = [];
        foreach (['a-active', 'b-pending', 'c-rejected', 'd-ghost'] as $local) {
            $this->requestReset($local . '@example.com');
            $bodies[] = (string) $this->client->getResponse()->getContent();
            $statuses[] = $this->client->getResponse()->getStatusCode();
        }

        self::assertCount(1, array_unique($bodies), 'reset-request bodies must not vary');
        self::assertCount(1, array_unique($statuses), 'reset-request statuses must not vary');
    }

    public function testUnsolvedAltchaIsRejected(): void
    {
        $this->factory()->create('active@example.com');

        $this->requestReset('active@example.com', 'garbage');

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        $payload = $this->payload();
        self::assertSame('validation_error', $payload['type']);
        self::assertIsArray($payload['errors']);
        self::assertArrayHasKey('altcha', $payload['errors']);
        self::assertEmailCount(0);
    }

    /**
     * Recovery must not depend on reproducing the exact casing used at signup -
     * the one thing a user who has lost their password is least likely to
     * remember.
     */
    public function testResetRequestFindsTheAccountRegardlessOfCasing(): void
    {
        $this->factory()->create('casey@example.com');

        $this->requestReset('CaseY@Example.COM');

        self::assertResponseIsSuccessful();
        self::assertEmailCount(1);
    }

    public function testResetChangesThePasswordEndToEnd(): void
    {
        $this->factory()->create('resetme@example.com');

        $this->requestReset('resetme@example.com');
        $token = $this->tokenFromMail();

        $this->post('/api/auth/password-reset', ['token' => $token, 'password' => self::NEW_PASSWORD]);
        self::assertResponseIsSuccessful();
        self::assertSame(['status' => 'reset'], $this->payload());

        $this->login('resetme@example.com', self::NEW_PASSWORD);
        self::assertResponseIsSuccessful();

        $this->login('resetme@example.com', 'correct-horse-battery');
        self::assertResponseStatusCodeSame(401);
        self::assertSame('invalid_credentials', $this->payload()['type']);
    }

    /**
     * A reset link sits in an inbox indefinitely. If it stayed live after use,
     * anyone who later reads that mailbox owns the account.
     */
    public function testAResetTokenIsSingleUse(): void
    {
        $user = $this->factory()->create('single@example.com');
        $token = $this->tokens()->issue($user, TokenPurpose::ResetPassword);

        $this->post('/api/auth/password-reset', ['token' => $token, 'password' => self::NEW_PASSWORD]);
        self::assertResponseIsSuccessful();

        $this->post('/api/auth/password-reset', ['token' => $token, 'password' => 'yet-another-passphrase']);
        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertSame('invalid_token', $this->payload()['type']);
    }

    /**
     * Purpose is half of what a token authorises. A verification link - which
     * anyone who registers an address they do not own can obtain - must never
     * double as permission to set a password.
     */
    public function testAVerificationTokenCannotResetAPassword(): void
    {
        $user = $this->factory()->create('crossuse@example.com');
        $token = $this->tokens()->issue($user, TokenPurpose::VerifyEmail);

        $this->post('/api/auth/password-reset', ['token' => $token, 'password' => self::NEW_PASSWORD]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_token', $this->payload()['type']);

        // And the old password must still work.
        $this->login('crossuse@example.com', 'correct-horse-battery');
        self::assertResponseIsSuccessful();
    }

    public function testUnknownTokenIsRejected(): void
    {
        $this->post('/api/auth/password-reset', [
            'token' => str_repeat('b', 64),
            'password' => self::NEW_PASSWORD,
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_token', $this->payload()['type']);
    }

    /**
     * The new password must clear the same bar as the one chosen at signup,
     * otherwise reset is a downgrade path around the length rule.
     */
    public function testAShortNewPasswordIsRejected(): void
    {
        $user = $this->factory()->create('shortpw@example.com');
        $token = $this->tokens()->issue($user, TokenPurpose::ResetPassword);

        $this->post('/api/auth/password-reset', ['token' => $token, 'password' => 'short']);

        self::assertResponseStatusCodeSame(422);
        $payload = $this->payload();
        self::assertSame('validation_error', $payload['type']);
        self::assertIsArray($payload['errors']);
        self::assertArrayHasKey('password', $payload['errors']);

        // Rejected before the service ran, so the token is still unspent.
        $this->post('/api/auth/password-reset', ['token' => $token, 'password' => self::NEW_PASSWORD]);
        self::assertResponseIsSuccessful();
    }

    /**
     * Issuing a fresh token must retire the earlier one, so a reset link that
     * leaked cannot be redeemed after the owner asks for a new one.
     */
    public function testIssuingASecondTokenRetiresTheFirst(): void
    {
        $user = $this->factory()->create('rotate@example.com');
        $first = $this->tokens()->issue($user, TokenPurpose::ResetPassword);
        $second = $this->tokens()->issue($user, TokenPurpose::ResetPassword);

        $this->post('/api/auth/password-reset', ['token' => $first, 'password' => self::NEW_PASSWORD]);
        self::assertResponseStatusCodeSame(400);

        $this->post('/api/auth/password-reset', ['token' => $second, 'password' => self::NEW_PASSWORD]);
        self::assertResponseIsSuccessful();
    }

    /**
     * The reset-request endpoint mails a live account-takeover link. Without a
     * cap, ALTCHA alone lets an attacker mail-bomb a known address at ~60 ms a
     * message until the recipient stops trusting the sender - or the relay
     * stops trusting us.
     */
    public function testSixthResetRequestFromOneIpIsThrottled(): void
    {
        $this->factory()->create('flooded@example.com');

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->requestReset('flooded@example.com');
            self::assertResponseIsSuccessful(sprintf('attempt %d should still be accepted', $attempt));
        }

        $this->requestReset('flooded@example.com');

        self::assertResponseStatusCodeSame(429);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertSame('rate_limited', $this->payload()['type']);
        self::assertEmailCount(0, message: 'a throttled request must not mail');

        $retryAfter = $this->client->getResponse()->headers->get('Retry-After');
        self::assertNotNull($retryAfter);
        self::assertGreaterThan(0, (int) $retryAfter);
        self::assertLessThanOrEqual(900, (int) $retryAfter);
    }

    /**
     * The limit is a cap on requests, not on successes. An unknown address must
     * consume budget too, otherwise enumeration by brute force stays free and
     * the endpoint's cost profile leaks which addresses exist.
     */
    public function testUnknownAddressesConsumeTheSameBudget(): void
    {
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->requestReset(sprintf('ghost%d@example.com', $attempt));
            self::assertResponseIsSuccessful();
        }

        $this->requestReset('ghost6@example.com');

        self::assertResponseStatusCodeSame(429);
    }

    /** The reset must not quietly promote or demote the account's status. */
    public function testResetLeavesTheAccountStatusAlone(): void
    {
        $user = $this->factory()->create('statusquo@example.com', status: UserStatus::Suspended);
        $token = $this->tokens()->issue($user, TokenPurpose::ResetPassword);

        $this->post('/api/auth/password-reset', ['token' => $token, 'password' => self::NEW_PASSWORD]);
        self::assertResponseIsSuccessful();

        self::assertInstanceOf(User::class, $user);
        self::assertSame(UserStatus::Suspended, $user->getStatus());
    }
}
