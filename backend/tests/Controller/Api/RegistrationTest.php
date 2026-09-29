<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\InstanceSettingsUpdate;
use App\Entity\User;
use App\Enum\TokenPurpose;
use App\Enum\UserStatus;
use App\Service\Auth\ActionTokenService;
use App\Service\Auth\AltchaService;
use App\Service\Settings\InstanceSettings;
use App\Tests\Support\AltchaSolver;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\EnablesMailInTests;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Mime\Email;

/**
 * Runtime note: every registration here solves a real ALTCHA proof-of-work
 * (~60 ms). This file is deliberately slower than the rest of the suite.
 */
final class RegistrationTest extends ApiTestCase
{
    use EnablesMailInTests;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = self::createClient();
        $this->seedEnabledMailInstance();

        // The /register and /password-reset-request limiters keep state in a filesystem pool that outlives the run.
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
        $payload = $this->payload($this->client);
        self::assertArrayHasKey('challenge', $payload);
        self::assertArrayHasKey('maxnumber', $payload);
        self::assertArrayHasKey('salt', $payload);
        self::assertArrayHasKey('signature', $payload);
    }

    public function testRegisterCreatesAPendingUserAndSendsOneMail(): void
    {
        $this->register();

        self::assertResponseStatusCodeSame(202);
        self::assertSame(['status' => 'pending_verification'], $this->payload($this->client));

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
        $payload = $this->payload($this->client);
        self::assertSame('validation_error', $payload['type']);
        self::assertArrayHasKey('errors', $payload);
        self::assertIsArray($payload['errors']);
        self::assertArrayHasKey('altcha', $payload['errors']);

        self::assertNull($this->users()->findOneByEmail('newcomer@example.com'));
        self::assertEmailCount(0);
    }

    /**
     * OAuthAccountLinker's placeholder, `<provider>-<sha256 prefix of sub>@oauth.invalid`, is deterministic: whoever
     * registered it first would make that identity's first sign-in fail on uniq_user_email.
     */
    public function testTheReservedOAuthPlaceholderDomainCannotBeRegistered(): void
    {
        $this->register(['email' => 'google-abc123@oauth.invalid', 'altcha' => 'unused']);

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        $payload = $this->payload($this->client);
        self::assertSame('validation_error', $payload['type']);
        self::assertIsArray($payload['errors']);
        self::assertArrayHasKey('email', $payload['errors']);

        self::assertNull($this->users()->findOneByEmail('google-abc123@oauth.invalid'));
        self::assertEmailCount(0);
    }

    /**
     * The whole reserved TLD, not just the one host the linker uses today: RFC
     * 2606 reserves `.invalid` precisely so it can never resolve, so no address
     * under it is ever a real one somebody should be able to sign up with.
     */
    public function testAnyInvalidTldAddressIsRefused(): void
    {
        $this->register(['email' => 'someone@whatever.invalid', 'altcha' => 'unused']);

        self::assertResponseStatusCodeSame(422);
        $payload = $this->payload($this->client);
        self::assertIsArray($payload['errors']);
        self::assertArrayHasKey('email', $payload['errors']);
    }

    /**
     * The other half of the constraint, and the one that would break OAuth
     * signup if it were put on the entity instead of on this DTO: an ordinary
     * address must still register normally.
     */
    public function testAnOrdinaryAddressStillRegisters(): void
    {
        $this->register(['email' => 'ordinary@example.com']);

        self::assertResponseStatusCodeSame(202);
        self::assertNotNull($this->users()->findOneByEmail('ordinary@example.com'));
    }

    /**
     * `invalid` as a label is not the reserved TLD: `invalid.example.com` is a real domain. The check is anchored at
     * the end, like OAuthIdentityModel::isPrivateRelay().
     */
    public function testADomainMerelyContainingInvalidStillRegisters(): void
    {
        $this->register(['email' => 'someone@invalid.example.com']);

        self::assertResponseStatusCodeSame(202);
        self::assertNotNull($this->users()->findOneByEmail('someone@invalid.example.com'));
    }

    public function testInvalidEmailAndShortPasswordAreBothReported(): void
    {
        $this->register(['email' => 'not-an-email', 'password' => 'short', 'altcha' => 'unused']);

        self::assertResponseStatusCodeSame(422);
        $payload = $this->payload($this->client);
        self::assertSame('validation_error', $payload['type']);
        self::assertIsArray($payload['errors']);
        self::assertArrayHasKey('email', $payload['errors']);
        self::assertArrayHasKey('password', $payload['errors']);
    }

    /**
     * The enumeration guarantee on the wire: status, headers and body are identical for a fresh and a taken address,
     * Date aside.
     */
    public function testDuplicateRegistrationIsByteIdentical(): void
    {
        $this->assertRegisterIsByteIdenticalForFreshAndDuplicateAddress('newcomer@example.com');

        self::assertCount(1, $this->users()->findBy(['email' => 'newcomer@example.com']));
    }

    /**
     * The same guarantee at both poles of RegistrationPolicy::prospectiveStatusForEmailSignup(): with email
     * confirmation required, and with both gates off, where a fresh signup lands straight in Active.
     */
    public function testRegisterResponseStaysIdenticalAcrossPolicies(): void
    {
        $this->assertRegisterIsByteIdenticalForFreshAndDuplicateAddress('policy-default@example.com');

        $this->instanceSettings()->update(new InstanceSettingsUpdate(
            requireEmailConfirmation: false,
            requireApproval: false,
            publicBaseUrl: null,
            passkeyRpId: null,
            passkeyRpName: null,
        ));
        $this->assertRegisterIsByteIdenticalForFreshAndDuplicateAddress('policy-open@example.com');
    }

    private function instanceSettings(): InstanceSettings
    {
        /** @var InstanceSettings $settings */
        $settings = self::getContainer()->get(InstanceSettings::class);

        return $settings;
    }

    private function assertRegisterIsByteIdenticalForFreshAndDuplicateAddress(string $email): void
    {
        $this->register(['email' => $email]);
        self::assertResponseStatusCodeSame(202);
        $first = $this->client->getResponse();
        $firstStatus = $first->getStatusCode();
        $firstBody = (string) $first->getContent();
        $firstHeaders = $first->headers->all();

        $this->register(['email' => $email]);
        $second = $this->client->getResponse();

        self::assertSame($firstStatus, $second->getStatusCode());
        self::assertSame($firstBody, (string) $second->getContent());

        unset($firstHeaders['date']);
        $secondHeaders = $second->headers->all();
        unset($secondHeaders['date']);
        self::assertSame($firstHeaders, $secondHeaders);
    }

    /**
     * A second verification mail in an existing user's inbox is an enumeration oracle with delivery attached. The
     * client reboots between requests, so the mailer log holds only the duplicate attempt's messages.
     */
    public function testDuplicateRegistrationSendsNoSecondMail(): void
    {
        $this->register();
        self::assertEmailCount(1, message: 'the first registration should mail');

        $this->register();
        self::assertEmailCount(0, message: 'the duplicate registration must mail nothing');
    }

    /**
     * Case variants are one account. Unnormalised, SQLite would store a second row and MySQL's _ci index would reject
     * the insert silently (the race handler swallows it): same assertion on both engines.
     */
    public function testACaseVariantOfAnExistingAddressIsTreatedAsADuplicate(): void
    {
        $this->register();
        self::assertResponseStatusCodeSame(202);

        $this->register(['email' => 'NewComer@Example.COM']);

        self::assertResponseStatusCodeSame(202);
        self::assertEmailCount(0, message: 'a case variant must not trigger a second verification mail');
        self::assertCount(1, $this->users()->findAll());
    }

    public function testVerificationMovesTheUserToPendingApproval(): void
    {
        $this->register();
        $token = $this->tokenFromMail();

        $this->post('/api/auth/verify-email', ['token' => $token]);

        self::assertResponseIsSuccessful();
        self::assertSame(['status' => 'pending_approval'], $this->payload($this->client));

        $user = $this->users()->findOneByEmail('newcomer@example.com');
        self::assertInstanceOf(User::class, $user);
        self::assertSame(UserStatus::PendingApproval, $user->getStatus());
    }

    public function testVerificationNotifiesAnActiveAdmin(): void
    {
        $this->factory()->create('boss@example.com', status: UserStatus::Active, roles: ['ROLE_ADMIN']);

        $this->register();
        $token = $this->tokenFromMail();

        $this->post('/api/auth/verify-email', ['token' => $token]);
        self::assertResponseIsSuccessful();

        // The kernel reboots between requests, so the only mail attributable to
        // this verify-email request is the admin notification.
        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertSame('boss@example.com', $mail->getTo()[0]->getAddress());

        $body = (string) $mail->getTextBody();
        self::assertStringContainsString('newcomer@example.com', $body);
        self::assertStringContainsString('/admin/users', $body);
        self::assertStringContainsString('Users awaiting approval: 1', $body);
    }

    public function testEachActiveAdminIsNotifiedInTheirOwnLanguage(): void
    {
        $this->factory()->create(
            'en-boss@example.com',
            status: UserStatus::Active,
            roles: ['ROLE_ADMIN'],
            locale: 'en',
        );
        $this->factory()->create(
            'de-boss@example.com',
            status: UserStatus::Active,
            roles: ['ROLE_ADMIN'],
            locale: 'de',
        );

        $this->register();
        $this->post('/api/auth/verify-email', ['token' => $this->tokenFromMail()]);

        self::assertEmailCount(2);

        $byRecipient = [];
        foreach (self::getMailerMessages() as $message) {
            self::assertInstanceOf(Email::class, $message);
            $byRecipient[$message->getTo()[0]->getAddress()] = $message->getSubject();
        }

        self::assertSame('A new user is awaiting approval', $byRecipient['en-boss@example.com']);
        self::assertSame('Ein neuer Nutzer wartet auf Freischaltung', $byRecipient['de-boss@example.com']);
    }

    public function testInactiveAdminsAndNonAdminsAreNotNotified(): void
    {
        $this->factory()->create('suspended-boss@example.com', status: UserStatus::Suspended, roles: ['ROLE_ADMIN']);
        $this->factory()->create('plain-user@example.com', status: UserStatus::Active);

        $this->register();
        $this->post('/api/auth/verify-email', ['token' => $this->tokenFromMail()]);

        self::assertEmailCount(0);
    }

    public function testVerificationWithNoActiveAdminSendsNothingAndDoesNotError(): void
    {
        $this->register();

        $this->post('/api/auth/verify-email', ['token' => $this->tokenFromMail()]);

        self::assertResponseIsSuccessful();
        self::assertSame(['status' => 'pending_approval'], $this->payload($this->client));
        self::assertEmailCount(0);
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
        self::assertSame('invalid_token', $this->payload($this->client)['type']);
    }

    public function testUnknownTokenIsRejected(): void
    {
        $this->post('/api/auth/verify-email', ['token' => str_repeat('a', 64)]);

        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertSame('invalid_token', $this->payload($this->client)['type']);
    }

    /**
     * A token longer than the column and the DTO allow must be refused by
     * validation, before it reaches the service or the database.
     */
    public function testAnOverlongTokenIsRejectedByValidation(): void
    {
        $this->post('/api/auth/verify-email', ['token' => str_repeat('a', 129)]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('validation_error', $this->payload($this->client)['type']);
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
        $payload = $this->payload($this->client);
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
     * ALTCHA sizes the cost of abuse; the limiter caps it. The sixth registration from one IP is refused, with
     * Retry-After.
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
        self::assertSame('rate_limited', $this->payload($this->client)['type']);

        $retryAfter = $this->client->getResponse()->headers->get('Retry-After');
        self::assertNotNull($retryAfter);
        self::assertGreaterThan(0, (int) $retryAfter);
        self::assertLessThanOrEqual(900, (int) $retryAfter);
    }

    /**
     * getClientIp() ignores X-Forwarded-For from untrusted senders, and nothing is trusted: a spoofed header must not
     * buy a fresh budget. Fails if trusted_proxies is ever widened carelessly.
     */
    public function testASpoofedForwardedForDoesNotBuyAFreshBudget(): void
    {
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->register();
        }

        $this->client->request(
            'POST',
            '/api/auth/register',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
            ],
            content: (string) json_encode([
                'email' => 'newcomer@example.com',
                'password' => 'correct-horse-battery',
                'altcha' => $this->altchaPayload(),
            ]),
        );

        self::assertResponseStatusCodeSame(429);
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
        // Must report what is true, not the usual case: this user can sign in
        // now, and telling them to wait for an admin would be a lie.
        self::assertSame(['status' => 'active'], $this->payload($this->client));
    }
}
