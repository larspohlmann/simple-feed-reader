<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\User;
use App\Entity\UserPasskey;
use App\Enum\UserStatus;
use App\Repository\UserPasskeyRepository;
use App\Service\Passkey\AssertionOptionsFactory;
use App\Service\Passkey\AssertionVerifier;
use App\Service\Passkey\NaiveUtcClock;
use App\Service\Passkey\PasskeyCeremony;
use App\Service\Passkey\PasskeyChallengeStore;
use App\Service\Passkey\PasskeySignInAvailability;
use App\Service\Settings\InstanceSettings;
use App\Service\Settings\InstanceSettingsUpdate;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\PasskeyAttestationFixture;
use App\Tests\Support\PasskeyFixtures;
use App\Tests\Support\PinsPasskeyRelyingParty;
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
 * WebAuthn login ("assertion") through PasskeyAuthenticator's own firewall
 * (#624 Task 10) — the flow that lets a user sign in with a passkey alone.
 *
 * Every scenario here reuses PasskeyFixtures::assertion() to sign a real
 * ECDSA assertion over a credential enrolled through the REAL registration
 * endpoint first (never a hand-built UserPasskey row) — see
 * AssertionVerifierTest's own docblock for why that matters. Relying party
 * and origin are pinned in every test, per PasskeyRegistrationTest's
 * convention, never left to resolve from APP_FRONTEND_URL.
 *
 * login_throttling persists attempt counters in a filesystem cache pool that
 * survives both the test and the whole run (#651) — both setUp() and
 * tearDown() clear it, copying PasskeyLoginOptionsTest's approach.
 *
 * @phpstan-import-type PasskeyAssertionCredentialPayload from PasskeyFixtures
 */
final class PasskeyLoginTest extends ApiTestCase
{
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
     * The structural guarantee the whole task is about: reusing json_login's
     * own success handler means the token's CLAIMS, not merely its shape,
     * are identical to password login's for the same account. Compared with
     * `iat`/`exp` stripped, since the two logins happen at different
     * instants.
     */
    public function testTheTokenClaimsMatchPasswordLoginForTheSameUser(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty(self::RELYING_PARTY_ID, 'Example Reader', self::ORIGIN);
        $this->factory()->create('claims@example.test');
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
    }

    public function testAReplayedHandleIsRejected(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty(self::RELYING_PARTY_ID, 'Example Reader', self::ORIGIN);
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
     * Mirrors PasskeyRegistrationTest::testAnExpiredHandleIsRejected: a
     * throwaway PasskeyChallengeStore over the SAME cache pool the
     * container-wired one reads from, with its own MockClock set minutes in
     * the past, so only this one entry is affected.
     */
    public function testAnExpiredHandleIsRejected(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty(self::RELYING_PARTY_ID, 'Example Reader', self::ORIGIN);
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
    }

    public function testACredentialIdThatWasNeverEnrolledIsRejected(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty(self::RELYING_PARTY_ID, 'Example Reader', self::ORIGIN);
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
    }

    /**
     * The scenario the brief calls out by name. AssertionVerifier's own
     * unit test (AssertionVerifierTest) pins the exact log fields; this
     * proves the SAME thing through the real firewall, by swapping the
     * container's AssertionVerifier for one built with a spy Logger — the
     * same technique LoginTest::testEveryCredentialFailureCostsTheSameOneHash
     * uses to observe a collaborator a functional request never returns
     * directly.
     */
    public function testABackwardsCounterIsRejectedAndLogsAWarning(): void
    {
        $client = static::createClient();
        // Without this, the client rebuilds the container before every
        // request and the set() override below would be discarded before
        // the login request that needs it ever ran.
        $client->disableReboot();
        $this->pinRelyingParty(self::RELYING_PARTY_ID, 'Example Reader', self::ORIGIN);
        $user = $this->factory()->create('clone-victim@example.test');
        $fixture = $this->enrol($client, 'clone-victim@example.test');
        // Swapped in BEFORE the first login: the container refuses set() on
        // an already-initialized service, and the first login below is what
        // would initialize the real one. Using this instance for both calls
        // is equivalent to the container's own AssertionVerifier — same
        // collaborators — with a spy Logger attached.
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
     * PasskeyAuthenticator::verifiedUser() guards this BEFORE calling
     * AssertionVerifier::verify(string $handle, array $credential) — both
     * parameters are typed, so a caller reaching that method with a null
     * handle would hit a TypeError, not the clean 401 every other rejection
     * in this suite produces. This proves the guard, not the verifier, is
     * what turns a malformed body into a normal login failure.
     */
    public function testAPayloadMissingTheHandleIsRejected(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty(self::RELYING_PARTY_ID, 'Example Reader', self::ORIGIN);

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
        $this->factory()->create('tampered@example.test');
        $fixture = $this->enrol($client, 'tampered@example.test');
        $handle = $this->issueLoginChallenge($fixture->challenge);
        $credential = PasskeyFixtures::assertion(self::RELYING_PARTY_ID, self::ORIGIN, $fixture->challenge, $fixture);

        $this->login($client, $handle, $this->withTamperedChallenge($credential));

        $this->assertRejected($client, 401);
    }

    /**
     * Fix round 1 (#624 Task 10): every OTHER negative case in this suite
     * trips a ceremony step earlier than CheckSignature — challenge, origin,
     * rpId, counter and credential-id resolution all short-circuit before
     * the signature is ever examined. Without this test, dropping
     * CheckSignature entirely (a library upgrade, a change to
     * CeremonyStepManagerFactory) would leave the whole suite green while
     * anyone holding a victim's credential id — stored in plain base64url
     * and echoed back in every assertion, not a secret — could log in as
     * them with a garbage signature.
     *
     * Verified this actually exercises CheckSignature and nothing earlier:
     * temporarily removed `new CheckSignature($this->algorithmManager)`
     * from CeremonyStepManagerFactory::requestCeremony() (vendor code) and
     * re-ran this file plus AssertionVerifierTest. ONLY this test failed
     * (200 instead of 401, since a request with a garbage signature was
     * then accepted); the other 20 tests across both files, including every
     * other negative case, stayed green. Restored the vendor file
     * immediately after — see task-10-report.md's "Fix round 1" section for
     * the full command output.
     */
    public function testATamperedSignatureIsRejected(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty(self::RELYING_PARTY_ID, 'Example Reader', self::ORIGIN);
        $this->factory()->create('bad-signature@example.test');
        $fixture = $this->enrol($client, 'bad-signature@example.test');
        $handle = $this->issueLoginChallenge($fixture->challenge);
        $credential = PasskeyFixtures::assertion(self::RELYING_PARTY_ID, self::ORIGIN, $fixture->challenge, $fixture);

        $this->login($client, $handle, $this->withTamperedSignature($credential));

        $this->assertRejected($client, 401);
    }

    /**
     * AssertionOptionsFactory's own docblock calls user verification "the
     * only check standing between 'the device is unlocked' and 'this
     * account is logged in'" for a passkey login — this is the regression
     * test for that enforcement actually firing on the ASSERTION side (the
     * registration side already has its own equivalent,
     * PasskeyRegistrationTest::testAnAttestationWithoutUserVerificationIsRejected).
     *
     * Verified load-bearing the same way that sibling test was: temporarily
     * relaxed AssertionOptionsFactory::optionsFor()'s userVerification to
     * PREFERRED and re-ran this file plus PasskeyLoginOptionsTest. Both this
     * test AND PasskeyLoginOptionsTest::testTheOptionsAreIssuedToAnAnonymousCaller
     * went red — exactly the coupling fix round 1's m1 fix intends:
     * AssertionOptionsFactory::optionsFor() is now the ONE place this
     * requirement lives, shared by the options endpoint and the verifier, so
     * a regression here cannot pass one half and fail the other silently.
     * Restored immediately after.
     */
    public function testAnAssertionWithoutUserVerificationIsRejected(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty(self::RELYING_PARTY_ID, 'Example Reader', self::ORIGIN);
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

    /**
     * Reuses the fixture the origin was signed against rather than
     * capturing a second one: only the SERVER's configured origin changes.
     */
    public function testAnOriginMismatchIsRejected(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty(self::RELYING_PARTY_ID, 'Example Reader', self::ORIGIN);
        $this->factory()->create('origin-mismatch@example.test');
        $fixture = $this->enrol($client, 'origin-mismatch@example.test');
        $handle = $this->issueLoginChallenge($fixture->challenge);
        // The server now accepts a different origin than the one the
        // fixture below is actually signed for.
        $this->pinRelyingParty(self::RELYING_PARTY_ID, 'Example Reader', 'https://different.test');

        $this->login($client, $handle, PasskeyFixtures::assertion(
            self::RELYING_PARTY_ID,
            self::ORIGIN,
            $fixture->challenge,
            $fixture,
        ));

        $this->assertRejected($client, 401);
    }

    public function testAnRpIdMismatchIsRejected(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty(self::RELYING_PARTY_ID, 'Example Reader', self::ORIGIN);
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
        $user = $this->factory()->create('suspended@example.test');
        $fixture = $this->enrol($client, 'suspended@example.test');
        $user->setStatus(UserStatus::Suspended);
        $this->em()->flush();
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

    /**
     * Ruling: max_attempts: 5 admits five attempts and rejects the sixth —
     * five failures, then the SIXTH is 429, never a seventh.
     */
    public function testTheSixthFailedAttemptFromOneIpIsThrottled(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty(self::RELYING_PARTY_ID, 'Example Reader', self::ORIGIN);
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
     * The trap the design brief calls out by name: the login path runs
     * through PasskeyAuthenticator, not PasskeyController, so
     * PasskeySignInAvailability's guard is wired into AssertionVerifier::
     * verify() instead — caught there by the SAME `catch (ApiException)`
     * block that already rewrites AssertionRejectedException, so a disabled
     * instance fails a passkey login exactly like a rejected assertion: a
     * clean 401 through LoginFailureHandler, never a 500.
     */
    public function testADisabledInstanceRejectsLoginWith401NotA500(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty(self::RELYING_PARTY_ID, 'Example Reader', self::ORIGIN);
        $this->factory()->create('disabled-login@example.test');
        $fixture = $this->enrol($client, 'disabled-login@example.test');
        $this->disablePasskeySignIn();
        $handle = $this->issueLoginChallenge($fixture->challenge);

        $this->login($client, $handle, PasskeyFixtures::assertion(
            self::RELYING_PARTY_ID,
            self::ORIGIN,
            $fixture->challenge,
            $fixture,
        ));

        $this->assertRejected($client, 401);
    }

    // -- Setup helpers -------------------------------------------------

    private function disablePasskeySignIn(): void
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

    /**
     * passkey_login has its OWN login_throttling budget, separate from the
     * password `login` firewall's — see PasskeyLoginOptionsTest for the same
     * cache-pool hygiene this copies, and #651 for why it matters.
     */
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

    /** A LOGIN challenge carries no user id or user handle — see PasskeyChallenge's docblock. */
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

    /**
     * Selects the record BY MESSAGE rather than assuming it is the first one
     * captured — a spy Logger attached to a real container service can see
     * other log lines (framework noise, other listeners) before the one this
     * test cares about.
     */
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
            $this->em(),
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
     * Flips one bit in the signature — enough to make it fail ECDSA
     * verification while leaving every earlier ceremony step (challenge,
     * origin, rpId, counter, credential id) untouched, so this is the one
     * negative case that reaches CheckSignature. See
     * testATamperedSignatureIsRejected for the removal experiment proving
     * that.
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

    /**
     * Clears the identity map first (#624 Task 10, fix round 1): without
     * this, the repository lookup below is served through the SAME entity
     * manager that handled the request, so Doctrine hands back the in-memory
     * mutated UserPasskey whether or not AssertionVerifier::verify() ever
     * flushed it — this repo's own #556-style trap. Clearing forces the
     * lookup to hit the database for real.
     */
    private function onlyStoredPasskeyFor(User $user): UserPasskey
    {
        $this->em()->clear();

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

    private function assertRejected(KernelBrowser $client, int $status): void
    {
        self::assertResponseStatusCodeSame($status);
        self::assertSame('application/problem+json', $client->getResponse()->headers->get('Content-Type'));
    }
}
