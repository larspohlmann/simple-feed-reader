<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\User;
use App\Entity\UserPasskey;
use App\Repository\UserPasskeyRepository;
use App\Service\Passkey\PasskeyChallengeStore;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\TogglesPasskeySignIn;
use App\Tests\Support\PasskeyAttestationFixture;
use App\Tests\Support\PasskeyFixtures;
use App\Tests\Support\PasskeyRegistrations;
use App\Tests\Support\PinsPasskeyRelyingParty;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\MockClock;

/**
 * Passkey registration, with `attestation: none` responses built in PHP (PasskeyFixtures). Every test pins the
 * relying party and origin itself, never the environment's APP_FRONTEND_URL: the mismatch tests depend on it.
 *
 * @phpstan-import-type PasskeyCredentialPayload from PasskeyAttestationFixture
 */
final class PasskeyRegistrationTest extends ApiTestCase
{
    use TogglesPasskeySignIn;
    use PinsPasskeyRelyingParty;

    public function testTheOptionsCarryTheRelyingPartyAndRequireUserVerification(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty('example.test', 'Example Reader', 'https://example.test');
        $this->serveFrom($client, 'https://example.test');
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
     * The server-side half of the sign-in switch: a toggle that only hides frontend buttons is cosmetic, so every
     * enrolment endpoint must itself refuse once the instance-wide switch is off.
     */
    public function testRegisterOptionsRefusesWhenPasskeySignInIsDisabled(): void
    {
        $client = static::createClient();
        $this->disablePasskeySignIn();
        $this->factory()->create('disabled-options@example.test');
        $this->authenticate($client, 'disabled-options@example.test');

        $client->request('POST', '/api/auth/passkey/register/options');

        self::assertResponseStatusCodeSame(403);
        self::assertSame('application/problem+json', $client->getResponse()->headers->get('Content-Type'));
    }

    public function testRegisterRefusesWhenPasskeySignInIsDisabled(): void
    {
        $client = static::createClient();
        $this->disablePasskeySignIn();
        $this->factory()->create('disabled-register@example.test');
        $this->authenticate($client, 'disabled-register@example.test');

        $client->request(
            'POST',
            '/api/auth/passkey/register',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(
                ['handle' => 'anything', 'credential' => ['id' => 'x'], 'label' => 'Test key'],
                \JSON_THROW_ON_ERROR,
            ),
        );

        self::assertResponseStatusCodeSame(403);
        self::assertSame('application/problem+json', $client->getResponse()->headers->get('Content-Type'));
    }

    /**
     * `rp.name` is required and shown in the enrolment prompt, so it must carry the configured name. Pinned apart
     * from `rp.id`, so one of the two cannot be threaded through alone.
     */
    public function testTheOptionsCarryTheConfiguredRelyingPartyName(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty('example.test', 'Example Reader', 'https://example.test');
        $this->serveFrom($client, 'https://example.test');
        $this->factory()->create('name-checker@example.test');
        $this->authenticate($client, 'name-checker@example.test');

        $client->request('POST', '/api/auth/passkey/register/options');

        $relyingParty = $this->arrayValue($this->options($this->payload($client)), 'rp');
        self::assertSame('Example Reader', $relyingParty['name']);
    }

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
        $this->enablePasskeySignIn();

        $client->request('POST', '/api/auth/passkey/register/options');

        $options = $this->options($this->payload($client));
        self::assertIsArray($options['excludeCredentials']);
        /** @var list<array<string, mixed>> $excludeCredentials */
        $excludeCredentials = $options['excludeCredentials'];
        self::assertSame(['Y3JlZC1hYmM'], array_column($excludeCredentials, 'id'));
    }

    public function testAValidAttestationStoresACredentialAndListsIt(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty('example.test', 'Example Reader', 'https://example.test');
        $this->serveFrom($client, 'https://example.test');
        $user = $this->factory()->create('enroller@example.test');
        $this->authenticate($client, 'enroller@example.test');
        [$fixture, $handle] = $this->seedRegistrationChallenge($user->getId(), 'example.test', 'https://example.test');

        $this->registerPasskey($client, $handle, $fixture->credential, 'My phone');

        self::assertResponseStatusCodeSame(201);
        $passkeys = $this->passkeysFromResponse($client);
        self::assertCount(1, $passkeys);
        self::assertSame('My phone', $passkeys[0]['label']);
        $body = $this->payload($client);
        self::assertSame('example.test', $body['rpId']);
        $stored = $this->onlyStoredPasskeyFor($user);
        self::assertSame($stored->getUserHandle(), $body['userHandle']);
        self::assertSame([$stored->getCredentialId()], $body['acceptedCredentialIds']);
    }

    /** Spec §5.2: a user who enrols from Settings is never shown the one-time offer again. */
    public function testAValidAttestationStampsTheOfferAnswered(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty('example.test', 'Example Reader', 'https://example.test');
        $this->serveFrom($client, 'https://example.test');
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
        $this->serveFrom($client, 'https://example.test');
        $user = $this->factory()->create('enroller@example.test');
        $this->authenticate($client, 'enroller@example.test');
        [$fixture, $handle] = $this->seedRegistrationChallenge($user->getId(), 'example.test', 'https://example.test');
        $this->registerPasskey($client, $handle, $fixture->credential, 'My phone');
        self::assertResponseStatusCodeSame(201);

        $this->registerPasskey($client, $handle, $fixture->credential, 'My phone, again');

        $this->assertRejected($client, 400);
    }

    /**
     * A throwaway PasskeyChallengeStore over the container's pool, with a MockClock minutes in the past, expires only
     * this entry: a MockClock in the container would change every functional test's clock.
     */
    public function testAnExpiredHandleIsRejected(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty('example.test', 'Example Reader', 'https://example.test');
        $this->serveFrom($client, 'https://example.test');
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
        $this->serveFrom($client, 'https://example.test');
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
        $this->serveFrom($client, 'https://example.test');
        $user = $this->factory()->create('enroller@example.test');
        $this->authenticate($client, 'enroller@example.test');
        [$fixture, $handle] = $this->seedRegistrationChallenge($user->getId(), 'example.test', 'https://example.test');

        $this->registerPasskey($client, $handle, $this->withTamperedChallenge($fixture->credential), 'My phone');

        $this->assertRejected($client, 400);
    }

    /**
     * A malformed attestationObject must be rejected, never a 500: deserialize() must stay inside
     * checkAgainstLibrary()'s broad catch.
     */
    public function testAGarbageAttestationObjectIsRejected(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty('example.test', 'Example Reader', 'https://example.test');
        $this->serveFrom($client, 'https://example.test');
        $user = $this->factory()->create('enroller@example.test');
        $this->authenticate($client, 'enroller@example.test');
        [$fixture, $handle] = $this->seedRegistrationChallenge($user->getId(), 'example.test', 'https://example.test');

        $this->registerPasskey($client, $handle, $this->withGarbageAttestationObject($fixture->credential), 'My phone');

        $this->assertRejected($client, 400);
    }

    /**
     * `attestation: none` is unsigned, so only our own configuration stops a caller from clearing the UV bit, and user
     * verification is the passkey's only factor: a UV-less registration must be refused.
     */
    public function testAnAttestationWithoutUserVerificationIsRejected(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty('example.test', 'Example Reader', 'https://example.test');
        $this->serveFrom($client, 'https://example.test');
        $user = $this->factory()->create('enroller@example.test');
        $this->authenticate($client, 'enroller@example.test');
        $fixture = PasskeyFixtures::attestation(
            'example.test',
            'https://example.test',
            random_bytes(32),
            random_bytes(16),
            random_bytes(32),
            flags: PasskeyFixtures::FLAG_USER_PRESENT | PasskeyFixtures::FLAG_ATTESTED_CREDENTIAL_DATA_INCLUDED,
        );
        $handle = $this->issueChallenge($fixture->challenge, $user->getId(), $this->randomUserHandle());

        $this->registerPasskey($client, $handle, $fixture->credential, 'My phone');

        $this->assertRejected($client, 400);
    }

    /**
     * Behind a dev-server proxy that rewrites Host, the server cannot see the browser's origin; a ceremony signed for
     * the configured relying party must still be accepted.
     */
    public function testACeremonyFromAProxiedOriginTheServerCannotSeeIsAccepted(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty('green-tara.example.ts.net', 'Reader', 'http://localhost:4200');
        $this->serveFrom($client, 'http://localhost');
        $user = $this->factory()->create('proxied@example.test');
        $this->authenticate($client, 'proxied@example.test');
        $fixture = $this->buildFixture('green-tara.example.ts.net', 'https://green-tara.example.ts.net');
        $handle = $this->issueChallenge($fixture->challenge, $user->getId(), $this->randomUserHandle());

        $this->registerPasskey($client, $handle, $fixture->credential, 'This Mac');

        self::assertResponseStatusCodeSame(201);
    }

    /** The browser's origin must belong to the relying-party id: a ceremony
     *  signed for an unrelated host is refused however it reached us. */
    public function testAnOriginMismatchIsRejected(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty('example.test', 'Example Reader', 'https://example.test');
        $this->serveFrom($client, 'https://example.test');
        $user = $this->factory()->create('enroller@example.test');
        $this->authenticate($client, 'enroller@example.test');
        $fixture = $this->buildFixture('example.test', 'https://evil.test');
        $handle = $this->issueChallenge($fixture->challenge, $user->getId(), $this->randomUserHandle());

        $this->registerPasskey($client, $handle, $fixture->credential, 'My phone');

        $this->assertRejected($client, 400);
    }

    public function testAnRpIdMismatchIsRejected(): void
    {
        $client = static::createClient();
        // 'example.test' is a valid parent of 'sub.example.test', so the availability guard passes, but the fixture's
        // authenticator data hashed 'different-domain.test': CheckRpIdHash must refuse it.
        $this->pinRelyingParty('example.test', 'Example Reader', 'https://sub.example.test');
        $this->serveFrom($client, 'https://sub.example.test');
        $user = $this->factory()->create('enroller@example.test');
        $this->authenticate($client, 'enroller@example.test');
        $fixture = $this->buildFixture('different-domain.test', 'https://sub.example.test');
        $handle = $this->issueChallenge($fixture->challenge, $user->getId(), $this->randomUserHandle());

        $this->registerPasskey($client, $handle, $fixture->credential, 'My phone');

        $this->assertRejected($client, 400);
    }

    public function testABlankLabelIsRejected(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty('example.test', 'Example Reader', 'https://example.test');
        $this->serveFrom($client, 'https://example.test');
        $user = $this->factory()->create('enroller@example.test');
        $this->authenticate($client, 'enroller@example.test');
        [$fixture, $handle] = $this->seedRegistrationChallenge($user->getId(), 'example.test', 'https://example.test');

        $this->registerPasskey($client, $handle, $fixture->credential, '');

        $this->assertRejected($client, 422);
    }

    /**
     * userHandleFor() mints a fresh handle per call while an account has no credential, so the stored handle must be
     * the one advertised at options time. Both requests run for real: the shortcut skips exactly that boundary.
     */
    public function testTheStoredUserHandleMatchesTheOneAdvertisedAtOptionsTime(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty('example.test', 'Example Reader', 'https://example.test');
        $this->serveFrom($client, 'https://example.test');
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
        $this->serveFrom($client, 'https://example.test');
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
     * A replayed or forged registration can still reach the unique constraint on `credential_id`: a clean 409,
     * never a 500.
     */
    public function testADuplicateCredentialIdIsRejected(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty('example.test', 'Example Reader', 'https://example.test');
        $this->serveFrom($client, 'https://example.test');
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
     * The library allows 1023 raw bytes, the column 191. Without UserPasskeyFactory's guard MySQL would 500 on the
     * flush and SQLite would store the row: both legs catch a missing guard.
     */
    public function testAnOverlongCredentialIdIsRejected(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty('example.test', 'Example Reader', 'https://example.test');
        $this->serveFrom($client, 'https://example.test');
        $user = $this->factory()->create('enroller@example.test');
        $this->authenticate($client, 'enroller@example.test');
        // 200 raw bytes: within the library's own 1023-byte ceiling, but its
        // base64url encoding (~267 chars) overflows the VARCHAR(255) column.
        $fixture = PasskeyFixtures::attestation(
            'example.test',
            'https://example.test',
            random_bytes(32),
            random_bytes(200),
            random_bytes(32),
        );
        $handle = $this->issueChallenge($fixture->challenge, $user->getId(), $this->randomUserHandle());

        $this->registerPasskey($client, $handle, $fixture->credential, 'My phone');

        $this->assertRejected($client, 400);
    }

    /**
     * 191 raw bytes encode to exactly 255 characters, the column's limit. The 200-byte test above cannot tell `<=`
     * from `<`.
     */
    public function testACredentialIdAtTheColumnLimitIsAccepted(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty('example.test', 'Example Reader', 'https://example.test');
        $this->serveFrom($client, 'https://example.test');
        $user = $this->factory()->create('at-limit@example.test');
        $this->authenticate($client, 'at-limit@example.test');
        $fixture = PasskeyFixtures::attestation(
            'example.test',
            'https://example.test',
            random_bytes(32),
            random_bytes(191),
            random_bytes(32),
        );
        $handle = $this->issueChallenge($fixture->challenge, $user->getId(), $this->randomUserHandle());

        $this->registerPasskey($client, $handle, $fixture->credential, 'My phone');

        self::assertResponseStatusCodeSame(201);
    }

    /** The converse of the test above: one raw byte more crosses the line. */
    public function testACredentialIdOneByteOverTheColumnLimitIsRejected(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty('example.test', 'Example Reader', 'https://example.test');
        $this->serveFrom($client, 'https://example.test');
        $user = $this->factory()->create('over-limit@example.test');
        $this->authenticate($client, 'over-limit@example.test');
        $fixture = PasskeyFixtures::attestation(
            'example.test',
            'https://example.test',
            random_bytes(32),
            random_bytes(192),
            random_bytes(32),
        );
        $handle = $this->issueChallenge($fixture->challenge, $user->getId(), $this->randomUserHandle());

        $this->registerPasskey($client, $handle, $fixture->credential, 'My phone');

        $this->assertRejected($client, 400);
    }

    /**
     * `response.transports` is unvalidated client data that excludeListFor() echoes to every later registration, so
     * only the spec's values may reach storage.
     */
    public function testAnUnknownTransportIsFilteredOutBeforeStorage(): void
    {
        $client = static::createClient();
        $this->pinRelyingParty('example.test', 'Example Reader', 'https://example.test');
        $this->serveFrom($client, 'https://example.test');
        $user = $this->factory()->create('transports@example.test');
        $this->authenticate($client, 'transports@example.test');
        $fixture = PasskeyFixtures::attestation(
            'example.test',
            'https://example.test',
            random_bytes(32),
            random_bytes(16),
            random_bytes(32),
        );
        $handle = $this->issueChallenge($fixture->challenge, $user->getId(), $this->randomUserHandle());
        $credential = $fixture->credential;
        // The bogus value comes first: array_intersect() preserves the
        // SOURCE array's keys, so a naive "keep the intersection" without
        // re-indexing would leave 'internal' at index 1, not 0.
        $credential['response']['transports'] = ['not-a-real-transport', 'internal'];

        $this->registerPasskey($client, $handle, $credential, 'My phone');

        self::assertResponseStatusCodeSame(201);
        /** @var UserPasskeyRepository $repository */
        $repository = self::getContainer()->get(UserPasskeyRepository::class);
        $stored = $repository->findForUser($user);
        self::assertSame(['internal'], $stored[0]->getTransports());
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
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function arrayValue(array $payload, string $key): array
    {
        self::assertIsArray($payload[$key]);

        /** @var array<string, mixed> $value */
        $value = $payload[$key];

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
     * Stores $credentialId and $userHandle verbatim, as base64url text: a readable id like 'Y3JlZC1hYmM' comes back
     * out of the serializer unchanged.
     */
    private function givenAPasskeyFor(User $user, string $credentialId, string $userHandle = 'aGFuZGxl'): void
    {
        $this->entityManager()->persist(new UserPasskey(
            $user,
            PasskeyRegistrations::any(credentialId: $credentialId, userHandle: $userHandle, label: 'Test key'),
        ));
        $this->entityManager()->flush();
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
     * Seeds PasskeyChallengeStore with a fixture's challenge and a made-up user handle, skipping the options request.
     * The user-handle tests do not use it: what they prove lives on the boundary it skips.
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
     * The challenge-store handle, the user handle as stored (base64url) and the raw challenge, read from an options
     * response.
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
     * Changes the challenge the client claims to have signed, leaving the attestationObject alone, so CheckChallenge
     * refuses it rather than an origin or relying-party check.
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
     * Truncates a valid attestationObject to 4 bytes, always incomplete CBOR, so the failure is deterministic.
     *
     * @param PasskeyCredentialPayload $credential
     *
     * @return PasskeyCredentialPayload
     */
    private function withGarbageAttestationObject(array $credential): array
    {
        $attestationObject = Base64UrlSafe::decodeNoPadding($credential['response']['attestationObject']);
        $credential['response']['attestationObject'] = Base64UrlSafe::encodeUnpadded(substr($attestationObject, 0, 4));

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
}
