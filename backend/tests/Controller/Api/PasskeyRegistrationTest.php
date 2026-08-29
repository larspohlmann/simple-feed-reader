<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\User;
use App\Entity\UserPasskey;
use App\Repository\UserPasskeyRepository;
use App\Service\Passkey\PasskeyChallengeStore;
use App\Service\Settings\InstanceSettings;
use App\Service\Settings\InstanceSettingsUpdate;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\PasskeyAttestationFixture;
use App\Tests\Support\PasskeyFixtures;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\MockClock;

/**
 * WebAuthn registration ("attestation"): issuing the options (#624, the
 * relying party, the resident-key/user-verification requirements, and the
 * exclude list that stops one authenticator enrolling twice on the same
 * account) and completing the ceremony — verifying the browser's response
 * and turning it into a stored credential.
 *
 * The completion tests use PasskeyFixtures to build a synthetic
 * `attestation: none` response entirely in PHP rather than capturing one
 * from a real browser — see that class's docblock for why this is possible
 * and not a shortcut. Every completion test pins BOTH the relying-party id
 * AND the public base URL (the origin) to the exact values its fixture was
 * built for, in the test itself, and never relies on `APP_FRONTEND_URL` or
 * any other environment default — the mismatch tests specifically exist to
 * prove what happens when one of the two is wrong, so both must otherwise be
 * pinned with certainty.
 *
 * Every test that reads `options.rp.id` pins the relying party explicitly via
 * the `instance_setting` row rather than asserting against whatever
 * `APP_FRONTEND_URL` happens to resolve to in this environment — see
 * ConfiguredPasskeyRelyingPartyTest for the same reasoning. A hard-coded
 * `'localhost'` would pass locally for the wrong reason and could fail in CI
 * for one unrelated to this feature.
 *
 * @phpstan-import-type PasskeyCredentialPayload from PasskeyAttestationFixture
 */
final class PasskeyRegistrationTest extends ApiTestCase
{
    public function testTheOptionsCarryTheRelyingPartyAndRequireUserVerification(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty('example.test', 'Example Reader');
        $this->factory()->create('enroller@example.test');
        $this->authenticate($client, 'enroller@example.test');

        $client->request('POST', '/api/auth/passkey/register/options');

        self::assertResponseIsSuccessful();
        $body = $this->payload($client);
        $options = $this->options($body);
        $relyingParty = $this->arrayValue($options, 'rp');
        $authenticatorSelection = $this->arrayValue($options, 'authenticatorSelection');
        self::assertSame('example.test', $relyingParty['id']);
        self::assertSame('required', $authenticatorSelection['userVerification']);
        self::assertSame('required', $authenticatorSelection['residentKey']);
        self::assertNotEmpty($body['handle']);
    }

    /**
     * `rp.name` is a required WebAuthn IDL member, not decoration: the
     * browser's and the password manager's enrolment prompt show it. Fix
     * round 1 (#624) found `RegistrationOptionsFactory` had silently emptied
     * it to dodge a library deprecation — this is the regression test for
     * that. Pinned separately from `rp.id` above so this test cannot pass by
     * accident if only one of the two ever gets threaded through.
     */
    public function testTheOptionsCarryTheConfiguredRelyingPartyName(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty('example.test', 'Example Reader');
        $this->factory()->create('name-checker@example.test');
        $this->authenticate($client, 'name-checker@example.test');

        $client->request('POST', '/api/auth/passkey/register/options');

        $relyingParty = $this->arrayValue($this->options($this->payload($client)), 'rp');
        self::assertSame('Example Reader', $relyingParty['name']);
    }

    /**
     * The narrower, single-endpoint form of the brief's four-path check. A
     * request to a path with no matching route 404s before the security
     * layer ever runs — confirmed with `bin/console debug:event-dispatcher
     * kernel.request`: `RouterListener` fires at priority 32, the firewall at
     * 8, and an unmatched route's `NotFoundHttpException` stops the
     * `kernel.request` event before the firewall's listener executes. Only
     * this one path has a controller in this task; `/passkey/register`,
     * `/passkeys` and `/passkeys/{id}` get their own anonymous-caller checks
     * once Tasks 7 and 8 give them one. All four paths' access_control
     * configuration — independent of whether a route exists yet — is proved
     * by PasskeyEnrolmentAccessControlTest.
     */
    public function testAnAnonymousCallerCannotRequestRegistrationOptions(): void
    {
        static::createClient()->request('POST', '/api/auth/passkey/register/options');

        self::assertResponseStatusCodeSame(401);
    }

    public function testTheExcludeListNamesTheCallersExistingCredentials(): void
    {
        $client = static::createClient();
        $user = $this->factory()->create('enroller@example.test');
        $this->givenAPasskeyFor($user, credentialId: 'Y3JlZC1hYmM');
        $this->authenticate($client, 'enroller@example.test');

        $client->request('POST', '/api/auth/passkey/register/options');

        $options = $this->options($this->payload($client));
        self::assertIsArray($options['excludeCredentials']);
        /** @var list<array<string, mixed>> $excludeCredentials */
        $excludeCredentials = $options['excludeCredentials'];
        self::assertSame(['Y3JlZC1hYmM'], array_column($excludeCredentials, 'id'));
    }

    // -- Completing the ceremony (#624 Task 7) ------------------------------

    public function testAValidAttestationStoresACredentialAndListsIt(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty('example.test', 'Example Reader', 'https://example.test');
        $user = $this->factory()->create('enroller@example.test');
        $this->authenticate($client, 'enroller@example.test');
        [$fixture, $handle] = $this->seedRegistrationChallenge($user->getId(), 'example.test', 'https://example.test');

        $this->registerPasskey($client, $handle, $fixture->credential, 'My phone');

        self::assertResponseStatusCodeSame(201);
        $passkeys = $this->passkeysFromResponse($client);
        self::assertCount(1, $passkeys);
        self::assertSame('My phone', $passkeys[0]['label']);
        /** @var UserPasskeyRepository $repository */
        $repository = self::getContainer()->get(UserPasskeyRepository::class);
        self::assertSame(1, $repository->countForUser($user));
    }

    /** Spec §5.2: a user who enrols from Settings is never shown the one-time offer again. */
    public function testAValidAttestationStampsTheOfferAnswered(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty('example.test', 'Example Reader', 'https://example.test');
        $user = $this->factory()->create('enroller@example.test');
        $this->authenticate($client, 'enroller@example.test');
        [$fixture, $handle] = $this->seedRegistrationChallenge($user->getId(), 'example.test', 'https://example.test');

        $this->registerPasskey($client, $handle, $fixture->credential, 'My phone');

        self::assertResponseStatusCodeSame(201);
        $stored = $this->users()->findOneByEmail('enroller@example.test');
        self::assertInstanceOf(User::class, $stored);
        self::assertNotNull($stored->getPreferences()->getPasskeyOfferAnsweredAt());
    }

    public function testAReplayedHandleIsRejected(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty('example.test', 'Example Reader', 'https://example.test');
        $user = $this->factory()->create('enroller@example.test');
        $this->authenticate($client, 'enroller@example.test');
        [$fixture, $handle] = $this->seedRegistrationChallenge($user->getId(), 'example.test', 'https://example.test');
        $this->registerPasskey($client, $handle, $fixture->credential, 'My phone');
        self::assertResponseStatusCodeSame(201);

        $this->registerPasskey($client, $handle, $fixture->credential, 'My phone, again');

        $this->assertRejected($client, 400);
    }

    /**
     * The window is not driven with a MockClock swapped into the whole test
     * container — see OAuthFlowTest's comment on the same question for why
     * that would taint every other functional test's clock. Instead a
     * throwaway PasskeyChallengeStore is built over the SAME cache pool the
     * real, container-wired one reads from, with its own MockClock set
     * minutes in the past; only the one entry it writes is affected.
     */
    public function testAnExpiredHandleIsRejected(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty('example.test', 'Example Reader', 'https://example.test');
        $user = $this->factory()->create('enroller@example.test');
        $this->authenticate($client, 'enroller@example.test');
        $fixture = $this->buildFixture('example.test', 'https://example.test');

        $handle = $this->issueExpiredChallenge($fixture->challenge, $user->getId(), $this->randomUserHandle());
        $this->registerPasskey($client, $handle, $fixture->credential, 'My phone');

        $this->assertRejected($client, 400);
    }

    /** This is the check that keeps a registration challenge bound to its owner. */
    public function testAHandleIssuedForADifferentUserIsRejected(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty('example.test', 'Example Reader', 'https://example.test');
        $this->factory()->create('caller@example.test');
        $owner = $this->factory()->create('owner@example.test');
        $this->authenticate($client, 'caller@example.test');
        [$fixture, $handle] = $this->seedRegistrationChallenge($owner->getId(), 'example.test', 'https://example.test');

        $this->registerPasskey($client, $handle, $fixture->credential, 'My phone');

        $this->assertRejected($client, 403);
    }

    public function testATamperedClientDataJsonIsRejected(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty('example.test', 'Example Reader', 'https://example.test');
        $user = $this->factory()->create('enroller@example.test');
        $this->authenticate($client, 'enroller@example.test');
        [$fixture, $handle] = $this->seedRegistrationChallenge($user->getId(), 'example.test', 'https://example.test');

        $this->registerPasskey($client, $handle, $this->withTamperedChallenge($fixture->credential), 'My phone');

        $this->assertRejected($client, 400);
    }

    /**
     * Reuses the fixture the origin was signed against rather than capturing
     * a second one: only the SERVER's configured origin changes, so this
     * proves the same boundary CheckAllowedOrigins enforces without a second
     * capture (PasskeyFixtures' own docblock explains why one call is enough).
     */
    public function testAnOriginMismatchIsRejected(): void
    {
        $client = static::createClient();
        // The server accepts a different origin than the one the fixture
        // below is actually signed for.
        $this->pinRelyingParty('example.test', 'Example Reader', 'https://different.test');
        $user = $this->factory()->create('enroller@example.test');
        $this->authenticate($client, 'enroller@example.test');
        $fixture = $this->buildFixture('example.test', 'https://example.test');
        $handle = $this->issueChallenge($fixture->challenge, $user->getId(), $this->randomUserHandle());

        $this->registerPasskey($client, $handle, $fixture->credential, 'My phone');

        $this->assertRejected($client, 400);
    }

    public function testAnRpIdMismatchIsRejected(): void
    {
        $client = static::createClient();
        // The server's configured relying-party id differs from the one the
        // fixture's authenticator data hashed at capture time.
        $this->pinRelyingParty('different-domain.test', 'Example Reader', 'https://example.test');
        $user = $this->factory()->create('enroller@example.test');
        $this->authenticate($client, 'enroller@example.test');
        $fixture = $this->buildFixture('example.test', 'https://example.test');
        $handle = $this->issueChallenge($fixture->challenge, $user->getId(), $this->randomUserHandle());

        $this->registerPasskey($client, $handle, $fixture->credential, 'My phone');

        $this->assertRejected($client, 400);
    }

    public function testABlankLabelIsRejected(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty('example.test', 'Example Reader', 'https://example.test');
        $user = $this->factory()->create('enroller@example.test');
        $this->authenticate($client, 'enroller@example.test');
        [$fixture, $handle] = $this->seedRegistrationChallenge($user->getId(), 'example.test', 'https://example.test');

        $this->registerPasskey($client, $handle, $fixture->credential, '');

        $this->assertRejected($client, 422);
    }

    // -- Fix round 1 (#624): the user handle must survive two requests -----

    /**
     * THE regression test for fix round 1. PasskeyCredentials::userHandleFor()
     * mints a fresh random value for an account's FIRST credential on every
     * call. Before this fix, RegistrationOptionsFactory::create() (at options
     * time) and AttestationVerifier (at verification time, a SEPARATE HTTP
     * request) each called it independently and got two different values, so
     * the row persisted here would never match the handle a real
     * authenticator remembers from the options response — breaking
     * discoverable login for every account's very first passkey. This test
     * cannot use seedRegistrationChallenge()'s shortcut: the bug lives
     * exactly on the boundary between the two requests, so it drives both
     * for real, through the actual options endpoint.
     */
    public function testTheStoredUserHandleMatchesTheOneAdvertisedAtOptionsTime(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty('example.test', 'Example Reader', 'https://example.test');
        $user = $this->factory()->create('enroller@example.test');
        $this->authenticate($client, 'enroller@example.test');

        $client->request('POST', '/api/auth/passkey/register/options');
        self::assertResponseIsSuccessful();
        [$handle, $advertisedUserHandle, $challenge] = $this->handleUserHandleAndChallengeFromOptions($client);

        $fixture = PasskeyFixtures::attestation(
            'example.test',
            'https://example.test',
            $challenge,
            random_bytes(16),
            random_bytes(32),
        );
        $this->registerPasskey($client, $handle, $fixture->credential, 'My phone');

        self::assertResponseStatusCodeSame(201);
        $stored = $this->onlyStoredPasskeyFor($user);
        self::assertSame($advertisedUserHandle, $stored->getUserHandle());
    }

    /**
     * The mirror case: PasskeyCredentials::userHandleFor() is deterministic
     * once an account has a credential, so a second enrolment must reuse
     * that same handle rather than the options endpoint minting a new one.
     */
    public function testASecondPasskeyReusesTheAccountsExistingUserHandle(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty('example.test', 'Example Reader', 'https://example.test');
        $user = $this->factory()->create('enroller@example.test');
        $this->givenAPasskeyFor($user, credentialId: 'ZXhpc3RpbmctY3JlZA', userHandle: 'ZXhpc3RpbmctaGFuZGxl');
        $this->authenticate($client, 'enroller@example.test');

        $client->request('POST', '/api/auth/passkey/register/options');
        self::assertResponseIsSuccessful();
        [$handle, $advertisedUserHandle, $challenge] = $this->handleUserHandleAndChallengeFromOptions($client);
        self::assertSame('ZXhpc3RpbmctaGFuZGxl', $advertisedUserHandle);

        $fixture = PasskeyFixtures::attestation(
            'example.test',
            'https://example.test',
            $challenge,
            random_bytes(16),
            random_bytes(32),
        );
        $this->registerPasskey($client, $handle, $fixture->credential, 'My second phone');

        self::assertResponseStatusCodeSame(201);
        /** @var UserPasskeyRepository $repository */
        $repository = self::getContainer()->get(UserPasskeyRepository::class);
        $stored = $repository->findForUser($user);
        self::assertCount(2, $stored);
        foreach ($stored as $passkey) {
            self::assertSame('ZXhpc3RpbmctaGFuZGxl', $passkey->getUserHandle());
        }
    }

    /**
     * The exclude list stops an honest client from re-submitting a
     * credential it already offered, but nothing stops a replayed or forged
     * request from reaching the database's own unique constraint on
     * `credential_id` — that must come back as a clean 409, never a 500.
     */
    public function testADuplicateCredentialIdIsRejected(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty('example.test', 'Example Reader', 'https://example.test');
        $user = $this->factory()->create('enroller@example.test');
        $this->authenticate($client, 'enroller@example.test');
        $credentialId = random_bytes(16);

        $fixtureOne = PasskeyFixtures::attestation(
            'example.test',
            'https://example.test',
            random_bytes(32),
            $credentialId,
            random_bytes(32),
        );
        $handleOne = $this->issueChallenge($fixtureOne->challenge, $user->getId(), $this->randomUserHandle());
        $this->registerPasskey($client, $handleOne, $fixtureOne->credential, 'My phone');
        self::assertResponseStatusCodeSame(201);

        // A fresh challenge and handle, but the SAME credential id — as if a
        // captured attestation were replayed onto a second ceremony.
        $fixtureTwo = PasskeyFixtures::attestation(
            'example.test',
            'https://example.test',
            random_bytes(32),
            $credentialId,
            random_bytes(32),
        );
        $handleTwo = $this->issueChallenge($fixtureTwo->challenge, $user->getId(), $this->randomUserHandle());

        $this->registerPasskey($client, $handleTwo, $fixtureTwo->credential, 'My other phone');

        $this->assertRejected($client, 409);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function options(array $body): array
    {
        return $this->arrayValue($body, 'options');
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function arrayValue(array $data, string $key): array
    {
        self::assertIsArray($data[$key]);

        /** @var array<string, mixed> $value */
        $value = $data[$key];

        return $value;
    }

    /** Attaches a bearer token to every subsequent request this client makes. */
    private function authenticate(KernelBrowser $client, string $email): void
    {
        $user = $this->users()->findOneByEmail($email);
        self::assertInstanceOf(User::class, $user);

        /** @var JWTTokenManagerInterface $manager */
        $manager = self::getContainer()->get(JWTTokenManagerInterface::class);

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $manager->create($user));
    }

    /**
     * Stores $credentialId verbatim, matching UserPasskeyTest's own
     * convention: it is treated as the value PasskeyCredentials::excludeListFor
     * decodes as base64url text, so a readable fixture like 'Y3JlZC1hYmM'
     * round-trips back out through the WebAuthn serializer unchanged. Same
     * for $userHandle, whose default keeps every existing caller unchanged.
     */
    private function givenAPasskeyFor(User $user, string $credentialId, string $userHandle = 'aGFuZGxl'): void
    {
        $this->em()->persist(new UserPasskey(
            $user,
            $credentialId,
            $userHandle,
            'cHVibGljLWtleQ',
            0,
            null,
            [],
            'Test key',
            new \DateTimeImmutable(),
        ));
        $this->em()->flush();
    }

    /**
     * $publicBaseUrl defaults to null (falling back to whatever
     * APP_FRONTEND_URL resolves to) only for the pre-existing options tests
     * above, which never validate an attestation against it. Every
     * completion test below passes an explicit value, per this class's
     * docblock.
     */
    private function pinRelyingParty(
        string $relyingPartyId,
        string $relyingPartyName,
        ?string $publicBaseUrl = null,
    ): void {
        /** @var InstanceSettings $settings */
        $settings = self::getContainer()->get(InstanceSettings::class);
        $settings->update(new InstanceSettingsUpdate(
            requireEmailConfirmation: true,
            requireApproval: true,
            publicBaseUrl: $publicBaseUrl,
            passkeyRpId: $relyingPartyId,
            passkeyRpName: $relyingPartyName,
        ));
    }

    private function buildFixture(string $relyingPartyId, string $origin): PasskeyAttestationFixture
    {
        return PasskeyFixtures::attestation(
            $relyingPartyId,
            $origin,
            random_bytes(32),
            random_bytes(16),
            random_bytes(32),
        );
    }

    /**
     * Builds a fixture and seeds PasskeyChallengeStore with the exact
     * challenge (and a made-up but valid user handle) it was signed against,
     * the way RegistrationOptionsFactory would have — so a test that does not
     * care about the handle itself can go straight to posting the completed
     * ceremony without a second round trip through the options endpoint.
     * testTheStoredUserHandleMatchesTheOneAdvertisedAtOptionsTime and its
     * mirror case below do NOT use this shortcut, because the fix-round-1 bug
     * they prove lives exactly on the boundary this shortcut skips.
     *
     * @return array{0: PasskeyAttestationFixture, 1: string}
     */
    private function seedRegistrationChallenge(?int $userId, string $relyingPartyId, string $origin): array
    {
        $fixture = $this->buildFixture($relyingPartyId, $origin);

        return [$fixture, $this->issueChallenge($fixture->challenge, $userId, $this->randomUserHandle())];
    }

    private function issueChallenge(string $challenge, ?int $userId, ?string $userHandle): string
    {
        /** @var PasskeyChallengeStore $store */
        $store = self::getContainer()->get(PasskeyChallengeStore::class);

        return $store->issue($challenge, $userId, $userHandle);
    }

    /**
     * Shares the container's own cache pool but not its clock — see
     * testAnExpiredHandleIsRejected for why that matters.
     */
    private function issueExpiredChallenge(string $challenge, ?int $userId, ?string $userHandle): string
    {
        /** @var CacheItemPoolInterface $pool */
        $pool = self::getContainer()->get('test.cache.passkey_challenge');

        return (new PasskeyChallengeStore($pool, new MockClock('2020-01-01 00:00:00')))
            ->issue($challenge, $userId, $userHandle);
    }

    /**
     * A syntactically valid but otherwise meaningless user handle, for tests
     * that must seed one to reach AttestationVerifier at all but do not
     * assert on its value.
     */
    private function randomUserHandle(): string
    {
        return Base64UrlSafe::encodeUnpadded(random_bytes(32));
    }

    /**
     * Reads the three values a completed registration ceremony needs out of
     * a `/register/options` response: the challenge-store handle, the user
     * handle the browser was shown (still base64url, as stored), and the
     * raw challenge bytes a fixture's clientDataJSON must be signed against.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function handleUserHandleAndChallengeFromOptions(KernelBrowser $client): array
    {
        $body = $this->payload($client);
        $handle = $body['handle'];
        self::assertIsString($handle);

        $options = $this->options($body);
        $userOption = $this->arrayValue($options, 'user');
        $userHandle = $userOption['id'];
        self::assertIsString($userHandle);

        $challengeOption = $options['challenge'];
        self::assertIsString($challengeOption);

        return [$handle, $userHandle, Base64UrlSafe::decodeNoPadding($challengeOption)];
    }

    private function onlyStoredPasskeyFor(User $user): UserPasskey
    {
        /** @var UserPasskeyRepository $repository */
        $repository = self::getContainer()->get(UserPasskeyRepository::class);
        $stored = $repository->findForUser($user);
        self::assertCount(1, $stored);

        return $stored[0];
    }

    /**
     * Mutates the CHALLENGE inside an already-built clientDataJSON, leaving
     * the attestationObject (and so the credential id and public key) alone.
     * Distinct from the origin/RP-id mismatch tests: those change what the
     * SERVER is configured to accept, this changes what the CLIENT claims to
     * have signed, so it exercises CheckChallenge rather than
     * CheckAllowedOrigins or CheckRelyingPartyIdIdHash.
     *
     * @param PasskeyCredentialPayload $credential
     *
     * @return PasskeyCredentialPayload
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
     * @param array<string, mixed> $credential
     */
    private function registerPasskey(KernelBrowser $client, string $handle, array $credential, string $label): void
    {
        $client->request(
            'POST',
            '/api/auth/passkey/register',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(
                ['handle' => $handle, 'credential' => $credential, 'label' => $label],
                \JSON_THROW_ON_ERROR,
            ),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function passkeysFromResponse(KernelBrowser $client): array
    {
        $body = $this->payload($client);
        self::assertIsArray($body['passkeys']);

        /** @var list<array<string, mixed>> $passkeys */
        $passkeys = $body['passkeys'];

        return $passkeys;
    }

    private function assertRejected(KernelBrowser $client, int $status): void
    {
        self::assertResponseStatusCodeSame($status);
        self::assertSame('application/problem+json', $client->getResponse()->headers->get('Content-Type'));
    }
}
