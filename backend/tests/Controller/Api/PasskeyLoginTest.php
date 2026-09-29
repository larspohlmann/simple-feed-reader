<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\InstanceSettingsUpdate;
use App\Entity\User;
use App\Entity\UserPasskey;
use App\EventListener\AddUserIdClaimOnTokenIssueListener;
use App\Repository\UserPasskeyRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Passkey\AssertionVerifier;
use App\Service\Passkey\Factory\AssertionOptionsFactory;
use App\Service\Passkey\PasskeyCeremony;
use App\Service\Passkey\PasskeyChallengeStore;
use App\Service\Passkey\PasskeySignInAvailability;
use App\Service\Settings\InstanceSettings;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\PasskeyAttestationFixture;
use App\Tests\Support\PasskeyFixtures;
use App\Tests\Support\PinsPasskeyRelyingParty;
use App\Tests\Support\TogglesPasskeySignIn;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\MockClock;

/**
 * Passkey login through PasskeyAuthenticator's firewall. Every scenario signs a real ECDSA assertion over a
 * credential enrolled through the real registration endpoint, with relying party and origin pinned in the test.
 *
 * @phpstan-import-type PasskeyAssertionCredentialPayload from PasskeyFixtures
 */
final class PasskeyLoginTest extends ApiTestCase
{
    use TogglesPasskeySignIn;
    use PinsPasskeyRelyingParty;

    private const string RELYING_PARTY_ID = 'example.test';
    private const string ORIGIN = 'https://example.test';
    private const string LOGIN_PATH = '/api/auth/passkey/login';

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearRateLimiterCache();
    }

    protected function tearDown(): void
    {
        $this->rateLimiterCache()->clear();

        parent::tearDown();
    }

    public function testAValidAssertionReturns200WithATokenThatAuthenticatesMe(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty(self::RELYING_PARTY_ID, 'Example Reader', self::ORIGIN);
        $this->serveFrom($client, self::ORIGIN);
        $this->factory()->create('login@example.test');
        $fixture = $this->enrol($client, 'login@example.test');
        $handle = $this->issueLoginChallenge($fixture->challenge);

        $this->login($client, $handle, PasskeyFixtures::assertion(
            self::RELYING_PARTY_ID,
            self::ORIGIN,
            $fixture->challenge,
            $fixture,
        ));

        self::assertResponseIsSuccessful();
        $token = $this->payload($client)['token'];
        self::assertIsString($token);

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $token);
        $client->request('GET', '/api/me');
        self::assertResponseIsSuccessful();
    }

    /**
     * Reusing json_login's success handler makes the token's claims, not just its shape, match password login for
     * the same account; `iat` and `exp` are stripped.
     */
    public function testTheTokenClaimsMatchPasswordLoginForTheSameUser(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty(self::RELYING_PARTY_ID, 'Example Reader', self::ORIGIN);
        $this->serveFrom($client, self::ORIGIN);
        $user = $this->factory()->create('claims@example.test');
        $fixture = $this->enrol($client, 'claims@example.test');
        $handle = $this->issueLoginChallenge($fixture->challenge);

        $client->request(
            'POST',
            '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['email' => 'claims@example.test', 'password' => 'correct-horse-battery']),
        );
        self::assertResponseIsSuccessful();
        $passwordToken = $this->payload($client)['token'];
        self::assertIsString($passwordToken);

        $this->login($client, $handle, PasskeyFixtures::assertion(
            self::RELYING_PARTY_ID,
            self::ORIGIN,
            $fixture->challenge,
            $fixture,
        ));
        self::assertResponseIsSuccessful();
        $passkeyToken = $this->payload($client)['token'];
        self::assertIsString($passkeyToken);

        self::assertSame($this->claimsExcludingTiming($passwordToken), $this->claimsExcludingTiming($passkeyToken));
        self::assertSame(
            $user->getId(),
            $this->claimsExcludingTiming($passkeyToken)[AddUserIdClaimOnTokenIssueListener::CLAIM] ?? null,
        );
    }

    public function testAReplayedHandleIsRejected(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty(self::RELYING_PARTY_ID, 'Example Reader', self::ORIGIN);
        $this->serveFrom($client, self::ORIGIN);
        $this->factory()->create('replay@example.test');
        $fixture = $this->enrol($client, 'replay@example.test');
        $handle = $this->issueLoginChallenge($fixture->challenge);
        $credential = PasskeyFixtures::assertion(self::RELYING_PARTY_ID, self::ORIGIN, $fixture->challenge, $fixture);
        $this->login($client, $handle, $credential);
        self::assertResponseIsSuccessful();

        $this->login($client, $handle, $credential);

        $this->assertRejected($client, 401);
    }

    /**
     * A throwaway PasskeyChallengeStore over the container's pool, with a MockClock minutes in the past, expires only
     * this one entry.
     */
    public function testAnExpiredHandleIsRejected(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty(self::RELYING_PARTY_ID, 'Example Reader', self::ORIGIN);
        $this->serveFrom($client, self::ORIGIN);
        $this->factory()->create('expired@example.test');
        $fixture = $this->enrol($client, 'expired@example.test');

        /** @var CacheItemPoolInterface $pool */
        $pool = self::getContainer()->get('test.cache.passkey_challenge');
        $handle = (new PasskeyChallengeStore($pool, new MockClock('2020-01-01 00:00:00')))
            ->issue($fixture->challenge, null, null);

        $this->login($client, $handle, PasskeyFixtures::assertion(
            self::RELYING_PARTY_ID,
            self::ORIGIN,
            $fixture->challenge,
            $fixture,
        ));

        $this->assertRejected($client, 401);
        // An expired challenge must not look like an unknown credential, or the browser would prune a working key.
        self::assertSame('invalid_credentials', $this->payload($client)['type']);
    }

    public function testACredentialIdThatWasNeverEnrolledIsRejected(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty(self::RELYING_PARTY_ID, 'Example Reader', self::ORIGIN);
        $this->serveFrom($client, self::ORIGIN);
        $neverEnrolled = PasskeyFixtures::attestation(
            self::RELYING_PARTY_ID,
            self::ORIGIN,
            random_bytes(32),
            random_bytes(16),
            random_bytes(32),
        );
        $handle = $this->issueLoginChallenge($neverEnrolled->challenge);

        $this->login($client, $handle, PasskeyFixtures::assertion(
            self::RELYING_PARTY_ID,
            self::ORIGIN,
            $neverEnrolled->challenge,
            $neverEnrolled,
        ));

        $this->assertRejected($client, 401);
        self::assertSame('unknown_passkey_credential', $this->payload($client)['type']);
    }

    /**
     * AssertionVerifierTest pins the log fields; this proves the same through the real firewall, with the container's
     * AssertionVerifier swapped for one on a spy logger.
     */
    public function testABackwardsCounterIsRejectedAndLogsAWarning(): void
    {
        $client = static::createClient();
        // Without this, the client rebuilds the container before every
        // request and the set() override below would be discarded before
        // the login request that needs it ever ran.
        $client->disableReboot();
        $this->pinRelyingParty(self::RELYING_PARTY_ID, 'Example Reader', self::ORIGIN);
        $this->serveFrom($client, self::ORIGIN);
        $user = $this->factory()->create('clone-victim@example.test');
        $fixture = $this->enrol($client, 'clone-victim@example.test');
        // Before the first login: set() refuses a service already initialised, and that login would initialise it.
        $logSpy = new TestHandler();
        self::getContainer()->set(AssertionVerifier::class, $this->verifierWithLogger($logSpy));
        $this->loginOnce($client, $fixture, signCount: 5);
        $handle = $this->issueLoginChallenge($fixture->challenge);

        $this->login($client, $handle, PasskeyFixtures::assertion(
            self::RELYING_PARTY_ID,
            self::ORIGIN,
            $fixture->challenge,
            $fixture,
            signCount: 3,
        ));

        $this->assertRejected($client, 401);
        $record = $this->warningRecordContaining($logSpy, 'signature counter did not advance');
        self::assertSame($this->onlyStoredPasskeyFor($user)->getCredentialId(), $record->context['credentialId']);
        self::assertSame($user->getId(), $record->context['userId']);
    }

    /**
     * verifiedUser() rejects a missing handle before calling verify(), whose typed parameters would otherwise throw a
     * TypeError instead of the 401 every other rejection gets.
     */
    public function testAPayloadMissingTheHandleIsRejected(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty(self::RELYING_PARTY_ID, 'Example Reader', self::ORIGIN);
        $this->serveFrom($client, self::ORIGIN);

        $client->request(
            'POST',
            self::LOGIN_PATH,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['credential' => ['id' => 'x']], \JSON_THROW_ON_ERROR),
        );

        $this->assertRejected($client, 401);
    }

    /** The converse of the case above: a handle with no credential array at all. */
    public function testAPayloadMissingTheCredentialIsRejected(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty(self::RELYING_PARTY_ID, 'Example Reader', self::ORIGIN);
        $this->serveFrom($client, self::ORIGIN);
        $this->factory()->create('missing-credential@example.test');
        $fixture = $this->enrol($client, 'missing-credential@example.test');
        $handle = $this->issueLoginChallenge($fixture->challenge);

        $client->request(
            'POST',
            self::LOGIN_PATH,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['handle' => $handle], \JSON_THROW_ON_ERROR),
        );

        $this->assertRejected($client, 401);
    }

    public function testATamperedClientDataJsonIsRejected(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty(self::RELYING_PARTY_ID, 'Example Reader', self::ORIGIN);
        $this->serveFrom($client, self::ORIGIN);
        $this->factory()->create('tampered@example.test');
        $fixture = $this->enrol($client, 'tampered@example.test');
        $handle = $this->issueLoginChallenge($fixture->challenge);
        $credential = PasskeyFixtures::assertion(self::RELYING_PARTY_ID, self::ORIGIN, $fixture->challenge, $fixture);

        $this->login($client, $handle, $this->withTamperedChallenge($credential));

        $this->assertRejected($client, 401);
    }

    /**
     * The only negative case that reaches CheckSignature: every other one fails an earlier step. Without it, losing
     * CheckSignature would leave the suite green while a credential id, which is no secret, logs anyone in.
     */
    public function testATamperedSignatureIsRejected(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty(self::RELYING_PARTY_ID, 'Example Reader', self::ORIGIN);
        $this->serveFrom($client, self::ORIGIN);
        $this->factory()->create('bad-signature@example.test');
        $fixture = $this->enrol($client, 'bad-signature@example.test');
        $handle = $this->issueLoginChallenge($fixture->challenge);
        $credential = PasskeyFixtures::assertion(self::RELYING_PARTY_ID, self::ORIGIN, $fixture->challenge, $fixture);

        $this->login($client, $handle, $this->withTamperedSignature($credential));

        $this->assertRejected($client, 401);
        self::assertSame('invalid_credentials', $this->payload($client)['type']);
    }

    /**
     * User verification is the only check between an unlocked device and a logged-in account. The requirement lives
     * in AssertionOptionsFactory::optionsFor() alone, so relaxing it fails this test and the options test together.
     */
    public function testAnAssertionWithoutUserVerificationIsRejected(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty(self::RELYING_PARTY_ID, 'Example Reader', self::ORIGIN);
        $this->serveFrom($client, self::ORIGIN);
        $this->factory()->create('no-uv@example.test');
        $fixture = $this->enrol($client, 'no-uv@example.test');
        $handle = $this->issueLoginChallenge($fixture->challenge);
        $credential = PasskeyFixtures::assertion(
            self::RELYING_PARTY_ID,
            self::ORIGIN,
            $fixture->challenge,
            $fixture,
            flags: PasskeyFixtures::FLAG_USER_PRESENT,
        );

        $this->login($client, $handle, $credential);

        $this->assertRejected($client, 401);
    }

    /** The browser's origin must belong to the relying-party id: an assertion
     *  signed for an unrelated host is refused however it reached us. */
    public function testAnOriginMismatchIsRejected(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty(self::RELYING_PARTY_ID, 'Example Reader', self::ORIGIN);
        $this->serveFrom($client, self::ORIGIN);
        $this->factory()->create('origin-mismatch@example.test');
        $fixture = $this->enrol($client, 'origin-mismatch@example.test');
        $handle = $this->issueLoginChallenge($fixture->challenge);

        $this->login($client, $handle, PasskeyFixtures::assertion(
            self::RELYING_PARTY_ID,
            'https://evil.test',
            $fixture->challenge,
            $fixture,
        ));

        $this->assertRejected($client, 401);
    }

    public function testAnRpIdMismatchIsRejected(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty(self::RELYING_PARTY_ID, 'Example Reader', self::ORIGIN);
        $this->serveFrom($client, self::ORIGIN);
        $this->factory()->create('rpid-mismatch@example.test');
        $fixture = $this->enrol($client, 'rpid-mismatch@example.test');
        $handle = $this->issueLoginChallenge($fixture->challenge);
        // The server's configured relying-party id now differs from the one
        // the fixture's authenticator data hashed at capture time.
        $this->pinRelyingParty('different-domain.test', 'Example Reader', self::ORIGIN);

        $this->login($client, $handle, PasskeyFixtures::assertion(
            self::RELYING_PARTY_ID,
            self::ORIGIN,
            $fixture->challenge,
            $fixture,
        ));

        $this->assertRejected($client, 401);
    }

    /** Proves App\Security\LoginUserChecker runs post-auth on this firewall too. */
    public function testASuspendedAccountIsRejectedWith403(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty(self::RELYING_PARTY_ID, 'Example Reader', self::ORIGIN);
        $this->serveFrom($client, self::ORIGIN);
        $user = $this->factory()->create('suspended@example.test');
        $fixture = $this->enrol($client, 'suspended@example.test');
        $user->suspend();
        $this->entityManager()->flush();
        $handle = $this->issueLoginChallenge($fixture->challenge);

        $this->login($client, $handle, PasskeyFixtures::assertion(
            self::RELYING_PARTY_ID,
            self::ORIGIN,
            $fixture->challenge,
            $fixture,
        ));

        self::assertResponseStatusCodeSame(403);
        self::assertSame('suspended', $this->payload($client)['accountStatus']);
    }

    /** max_attempts: 5 admits five failures and rejects the sixth with 429, never a seventh. */
    public function testTheSixthFailedAttemptFromOneIpIsThrottled(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty(self::RELYING_PARTY_ID, 'Example Reader', self::ORIGIN);
        $this->serveFrom($client, self::ORIGIN);
        $neverEnrolled = PasskeyFixtures::attestation(
            self::RELYING_PARTY_ID,
            self::ORIGIN,
            random_bytes(32),
            random_bytes(16),
            random_bytes(32),
        );

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $handle = $this->issueLoginChallenge($neverEnrolled->challenge);
            $this->login($client, $handle, PasskeyFixtures::assertion(
                self::RELYING_PARTY_ID,
                self::ORIGIN,
                $neverEnrolled->challenge,
                $neverEnrolled,
            ));
            self::assertResponseStatusCodeSame(401, \sprintf('attempt %d should still be 401', $attempt));
        }

        $handle = $this->issueLoginChallenge($neverEnrolled->challenge);
        $this->login($client, $handle, PasskeyFixtures::assertion(
            self::RELYING_PARTY_ID,
            self::ORIGIN,
            $neverEnrolled->challenge,
            $neverEnrolled,
        ));

        self::assertResponseStatusCodeSame(429);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

    public function testASuccessfulAssertionStampsLastUsedAtAndStoresTheNewCounter(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty(self::RELYING_PARTY_ID, 'Example Reader', self::ORIGIN);
        $this->serveFrom($client, self::ORIGIN);
        $user = $this->factory()->create('counter@example.test');
        $fixture = $this->enrol($client, 'counter@example.test');
        $handle = $this->issueLoginChallenge($fixture->challenge);

        $this->login($client, $handle, PasskeyFixtures::assertion(
            self::RELYING_PARTY_ID,
            self::ORIGIN,
            $fixture->challenge,
            $fixture,
            signCount: 9,
        ));

        self::assertResponseIsSuccessful();
        $stored = $this->onlyStoredPasskeyFor($user);
        self::assertSame(9, $stored->getSignatureCounter());
        self::assertNotNull($stored->getLastUsedAt());
    }

    /**
     * The login path runs through PasskeyAuthenticator, so the availability guard lives in AssertionVerifier.
     * The relying party stays pinned, making the toggle the only variable: every other passkey failure reads as
     * invalid_credentials, so only a request that would otherwise succeed proves the guard rejected it.
     */
    public function testADisabledInstanceRejectsLoginWith401NotA500(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty(self::RELYING_PARTY_ID, 'Example Reader', self::ORIGIN);
        $this->serveFrom($client, self::ORIGIN);
        $this->factory()->create('disabled-login@example.test');
        $fixture = $this->enrol($client, 'disabled-login@example.test');
        $this->disablePasskeySignInKeepingRelyingParty();
        $handle = $this->issueLoginChallenge($fixture->challenge);

        $this->login($client, $handle, PasskeyFixtures::assertion(
            self::RELYING_PARTY_ID,
            self::ORIGIN,
            $fixture->challenge,
            $fixture,
        ));

        $this->assertRejected($client, 401);
    }

    /**
     * Disables the toggle but keeps the relying party the fixture was built for, so the toggle is the only variable.
     * Not TogglesPasskeySignIn, which also resets the relying party.
     */
    private function disablePasskeySignInKeepingRelyingParty(): void
    {
        /** @var InstanceSettings $settings */
        $settings = self::getContainer()->get(InstanceSettings::class);
        $settings->update(new InstanceSettingsUpdate(
            requireEmailConfirmation: true,
            requireApproval: true,
            publicBaseUrl: self::ORIGIN,
            passkeyRpId: self::RELYING_PARTY_ID,
            passkeyRpName: 'Example Reader',
            passkeySignInEnabled: false,
        ));
    }

    /** passkey_login has its own login_throttling budget, cleared like PasskeyLoginOptionsTest's pool. */
    private function clearRateLimiterCache(): void
    {
        self::bootKernel();
        $this->rateLimiterCache()->clear();
        self::ensureKernelShutdown();
    }

    private function rateLimiterCache(): CacheItemPoolInterface
    {
        /** @var CacheItemPoolInterface $cache */
        $cache = self::getContainer()->get('test.cache.rate_limiter');

        return $cache;
    }

    /** Enrols a fresh passkey for $email through the real HTTP registration endpoints. */
    private function enrol(KernelBrowser $client, string $email): PasskeyAttestationFixture
    {
        $this->authenticateAs($client, $email);
        $fixture = PasskeyFixtures::attestation(
            self::RELYING_PARTY_ID,
            self::ORIGIN,
            random_bytes(32),
            random_bytes(16),
            random_bytes(32),
        );
        $registrationHandle = $this->issueRegistrationChallenge($fixture, $email);

        $client->request(
            'POST',
            '/api/auth/passkey/register',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(
                ['handle' => $registrationHandle, 'credential' => $fixture->credential, 'label' => 'Test key'],
                \JSON_THROW_ON_ERROR,
            ),
        );
        self::assertResponseStatusCodeSame(201);

        // The login flow is anonymous; nothing about it should depend on a
        // bearer token left over from enrolling the credential.
        $client->setServerParameter('HTTP_AUTHORIZATION', '');

        return $fixture;
    }

    private function authenticateAs(KernelBrowser $client, string $email): void
    {
        $user = $this->users()->findOneByEmail($email);
        self::assertInstanceOf(User::class, $user);

        /** @var JWTTokenManagerInterface $manager */
        $manager = self::getContainer()->get(JWTTokenManagerInterface::class);

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $manager->create($user));
    }

    private function issueRegistrationChallenge(PasskeyAttestationFixture $fixture, string $email): string
    {
        $user = $this->users()->findOneByEmail($email);
        self::assertInstanceOf(User::class, $user);

        /** @var PasskeyChallengeStore $store */
        $store = self::getContainer()->get(PasskeyChallengeStore::class);

        return $store->issue($fixture->challenge, $user->getId(), Base64UrlSafe::encodeUnpadded($fixture->userHandle));
    }

    /** A LOGIN challenge carries no user id or user handle — see PasskeyChallengeModel's docblock. */
    private function issueLoginChallenge(string $challenge): string
    {
        /** @var PasskeyChallengeStore $store */
        $store = self::getContainer()->get(PasskeyChallengeStore::class);

        return $store->issue($challenge, userId: null, userHandle: null);
    }

    /** One successful login, to advance the stored counter before a "goes backwards" scenario. */
    private function loginOnce(KernelBrowser $client, PasskeyAttestationFixture $fixture, int $signCount): void
    {
        $handle = $this->issueLoginChallenge($fixture->challenge);
        $this->login($client, $handle, PasskeyFixtures::assertion(
            self::RELYING_PARTY_ID,
            self::ORIGIN,
            $fixture->challenge,
            $fixture,
            signCount: $signCount,
        ));
        self::assertResponseIsSuccessful();
    }

    /**
     * @param array<string, mixed> $credential
     */
    private function login(KernelBrowser $client, string $handle, array $credential): void
    {
        $client->request(
            'POST',
            self::LOGIN_PATH,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['handle' => $handle, 'credential' => $credential], \JSON_THROW_ON_ERROR),
        );
    }

    /** Selects the record by message: a spy on a real container service also sees framework log lines. */
    private function warningRecordContaining(TestHandler $logSpy, string $needle): LogRecord
    {
        foreach ($logSpy->getRecords() as $record) {
            if (Level::Warning === $record->level && str_contains($record->message, $needle)) {
                return $record;
            }
        }

        self::fail(\sprintf('No warning log record contains "%s".', $needle));
    }

    private function verifierWithLogger(TestHandler $logSpy): AssertionVerifier
    {
        /** @var PasskeyChallengeStore $challengeStore */
        $challengeStore = self::getContainer()->get(PasskeyChallengeStore::class);
        /** @var PasskeyCeremony $ceremony */
        $ceremony = self::getContainer()->get(PasskeyCeremony::class);
        /** @var AssertionOptionsFactory $optionsFactory */
        $optionsFactory = self::getContainer()->get(AssertionOptionsFactory::class);
        /** @var UserPasskeyRepository $passkeys */
        $passkeys = self::getContainer()->get(UserPasskeyRepository::class);
        /** @var NaiveUtcClock $clock */
        $clock = self::getContainer()->get(NaiveUtcClock::class);
        /** @var PasskeySignInAvailability $availability */
        $availability = self::getContainer()->get(PasskeySignInAvailability::class);

        return new AssertionVerifier(
            $challengeStore,
            $ceremony,
            $optionsFactory,
            $passkeys,
            $this->entityManager(),
            $clock,
            new Logger('test', [$logSpy]),
            $availability,
        );
    }

    /**
     * @param PasskeyAssertionCredentialPayload $credential
     *
     * @return PasskeyAssertionCredentialPayload
     */
    private function withTamperedChallenge(array $credential): array
    {
        $clientDataJson = Base64UrlSafe::decodeNoPadding($credential['response']['clientDataJSON']);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($clientDataJson, true, flags: \JSON_THROW_ON_ERROR);
        $decoded['challenge'] = Base64UrlSafe::encodeUnpadded(random_bytes(32));

        $credential['response']['clientDataJSON'] = Base64UrlSafe::encodeUnpadded(
            (string) json_encode($decoded, \JSON_THROW_ON_ERROR),
        );

        return $credential;
    }

    /**
     * Flips one bit of the signature: ECDSA fails while every earlier step passes, so only CheckSignature can refuse.
     *
     * @param PasskeyAssertionCredentialPayload $credential
     *
     * @return PasskeyAssertionCredentialPayload
     */
    private function withTamperedSignature(array $credential): array
    {
        $signature = Base64UrlSafe::decodeNoPadding($credential['response']['signature']);
        $signature[0] = \chr(\ord($signature[0]) ^ 0xFF);
        $credential['response']['signature'] = Base64UrlSafe::encodeUnpadded($signature);

        return $credential;
    }

    /** Clears the identity map, or Doctrine returns the mutated entity whether or not verify() ever flushed it. */
    private function onlyStoredPasskeyFor(User $user): UserPasskey
    {
        $this->entityManager()->clear();

        /** @var UserPasskeyRepository $repository */
        $repository = self::getContainer()->get(UserPasskeyRepository::class);
        $stored = $repository->findForUser($user);
        self::assertCount(1, $stored);

        return $stored[0];
    }

    /**
     * @return array<string, mixed>
     */
    private function claimsExcludingTiming(string $token): array
    {
        /** @var JWTTokenManagerInterface $manager */
        $manager = self::getContainer()->get(JWTTokenManagerInterface::class);
        /** @var array<string, mixed> $claims */
        $claims = $manager->parse($token);
        unset($claims['iat'], $claims['exp']);

        return $claims;
    }
}
