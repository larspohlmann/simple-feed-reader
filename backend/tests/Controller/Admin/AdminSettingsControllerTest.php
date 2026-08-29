<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\User;
use App\Entity\UserPasskey;
use App\Repository\UserPasskeyRepository;
use App\Tests\Support\ApiTestCase;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The two registration-gate toggles plus the admin-configured passkey
 * relying party (#624), all admin-facing. `/api/admin/settings` is covered by
 * the existing `^/api/admin/` ROLE_ADMIN prefix rule in security.yaml — no
 * new access_control entry needed, confirmed by reading it before writing
 * this test.
 */
final class AdminSettingsControllerTest extends ApiTestCase
{
    private const string SETTINGS = '/api/admin/settings';

    private KernelBrowser $client;

    /**
     * Created once, up front: createClient() refuses to run after any other
     * container access (factory(), em()…) has already booted the kernel, so
     * every other helper below must reuse this one browser instead of
     * calling createClient() again.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->client = self::createClient();
    }

    private function tokenFor(User $user): string
    {
        /** @var JWTTokenManagerInterface $manager */
        $manager = self::getContainer()->get(JWTTokenManagerInterface::class);

        return $manager->create($user);
    }

    private function authenticateAs(User $user): KernelBrowser
    {
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $this->tokenFor($user));

        return $this->client;
    }

    private function adminClient(string $email = 'boss@example.com'): KernelBrowser
    {
        return $this->authenticateAs($this->factory()->create($email, roles: ['ROLE_ADMIN']));
    }

    private function passkeys(): UserPasskeyRepository
    {
        /** @var UserPasskeyRepository $repository */
        $repository = self::getContainer()->get(UserPasskeyRepository::class);

        return $repository;
    }

    /**
     * There is no enrolment endpoint yet (it arrives in a later task), so the
     * fixture builds the row directly through the entity manager, exactly as
     * an enrolment would leave it.
     */
    private function givenAnEnrolledPasskey(): UserPasskey
    {
        $owner = $this->factory()->create('passkey-owner-' . bin2hex(random_bytes(4)) . '@example.com');

        $passkey = new UserPasskey(
            $owner,
            credentialId: bin2hex(random_bytes(16)),
            userHandle: bin2hex(random_bytes(16)),
            publicKey: 'test-public-key',
            signatureCounter: 0,
            aaguid: null,
            transports: [],
            label: 'Test passkey',
            createdAt: new \DateTimeImmutable('2026-08-29 10:00:00'),
        );

        $this->em()->persist($passkey);
        $this->em()->flush();

        return $passkey;
    }

    public function testGetReturnsCurrentSettingsForAnAdmin(): void
    {
        $client = $this->adminClient();

        $client->request('GET', self::SETTINGS);

        self::assertResponseIsSuccessful();
        self::assertSame(
            [
                'requireEmailConfirmation' => true,
                'requireApproval' => true,
                'mailEnabled' => true,
                'publicBaseUrl' => null,
                'passkeyRpId' => null,
                'passkeyRpName' => null,
                // Derived from APP_FRONTEND_URL (http://localhost:4200) in the test env.
                'passkeyRpIdEffective' => 'localhost',
            ],
            $this->payload($client),
        );
    }

    public function testPutUpdatesTheToggles(): void
    {
        $client = $this->adminClient();

        $client->jsonRequest('PUT', self::SETTINGS, [
            'requireEmailConfirmation' => false,
            'requireApproval' => true,
            'publicBaseUrl' => null,
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(
            [
                'requireEmailConfirmation' => false,
                'requireApproval' => true,
                'mailEnabled' => true,
                'publicBaseUrl' => null,
                'passkeyRpId' => null,
                'passkeyRpName' => null,
                'passkeyRpIdEffective' => 'localhost',
            ],
            $this->payload($client),
        );

        $client->request('GET', self::SETTINGS);

        self::assertResponseIsSuccessful();
        self::assertSame(
            [
                'requireEmailConfirmation' => false,
                'requireApproval' => true,
                'mailEnabled' => true,
                'publicBaseUrl' => null,
                'passkeyRpId' => null,
                'passkeyRpName' => null,
                'passkeyRpIdEffective' => 'localhost',
            ],
            $this->payload($client),
        );
    }

    public function testPutPersistsThePublicBaseUrl(): void
    {
        $client = $this->adminClient();

        $client->jsonRequest('PUT', self::SETTINGS, [
            'requireEmailConfirmation' => true,
            'requireApproval' => true,
            'publicBaseUrl' => 'https://reader.example.ts.net/reader',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('https://reader.example.ts.net/reader', $this->payload($client)['publicBaseUrl']);
    }

    public function testPutRejectsAMalformedPublicBaseUrl(): void
    {
        $client = $this->adminClient();

        $client->jsonRequest('PUT', self::SETTINGS, [
            'requireEmailConfirmation' => true,
            'requireApproval' => true,
            'publicBaseUrl' => 'not a url',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

    public function testPutRejectsANonBooleanPayload(): void
    {
        $client = $this->adminClient();

        $client->jsonRequest('PUT', self::SETTINGS, [
            'requireEmailConfirmation' => 'nope',
            'requireApproval' => true,
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

    public function testNonAdminIsForbidden(): void
    {
        $client = $this->authenticateAs($this->factory()->create('plain@example.com'));

        $client->request('GET', self::SETTINGS);

        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

    public function testAnRelyingPartyIdThatIsNotASuffixOfTheHostIsRefused(): void
    {
        $client = $this->adminClient();

        $client->jsonRequest('PUT', self::SETTINGS, [
            'requireEmailConfirmation' => true,
            'requireApproval' => true,
            'publicBaseUrl' => 'https://lars-pohlmann.de',
            'passkeyRpId' => 'evil.test',
            'passkeyRpName' => 'Reader',
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testChangingTheRelyingPartyIdIsRefusedWhileCredentialsExist(): void
    {
        $this->givenAnEnrolledPasskey();
        $client = $this->adminClient();

        $client->jsonRequest('PUT', self::SETTINGS, [
            'requireEmailConfirmation' => true,
            'requireApproval' => true,
            'publicBaseUrl' => 'https://lars-pohlmann.de',
            'passkeyRpId' => 'lars-pohlmann.de',
            'passkeyRpName' => 'Reader',
        ]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(1, $this->payload($client)['invalidatedPasskeyCount']);
    }

    public function testAConfirmedChangeDeletesEveryCredential(): void
    {
        $this->givenAnEnrolledPasskey();
        $client = $this->adminClient();

        $client->jsonRequest('PUT', self::SETTINGS, [
            'requireEmailConfirmation' => true,
            'requireApproval' => true,
            'publicBaseUrl' => 'https://lars-pohlmann.de',
            'passkeyRpId' => 'lars-pohlmann.de',
            'passkeyRpName' => 'Reader',
            'invalidateExistingPasskeys' => true,
        ]);

        self::assertResponseIsSuccessful();
        // A fresh repository read, not an entity handle: after a bulk DELETE,
        // find() would serve the stale identity map instead of the real state.
        self::assertSame(0, $this->passkeys()->countAll());
    }

    /**
     * Only a CHANGE is guarded — resending the id already in effect must
     * always succeed, or an admin with an enrolled passkey could never touch
     * the other three fields again.
     */
    public function testResendingTheSameRelyingPartyIdSucceedsWithCredentialsPresent(): void
    {
        $client = $this->adminClient();

        $client->jsonRequest('PUT', self::SETTINGS, [
            'requireEmailConfirmation' => true,
            'requireApproval' => true,
            'publicBaseUrl' => 'https://lars-pohlmann.de',
            'passkeyRpId' => 'lars-pohlmann.de',
            'passkeyRpName' => 'Reader',
        ]);
        self::assertResponseIsSuccessful();

        $this->givenAnEnrolledPasskey();

        $client->jsonRequest('PUT', self::SETTINGS, [
            'requireEmailConfirmation' => true,
            'requireApproval' => true,
            'publicBaseUrl' => 'https://lars-pohlmann.de',
            'passkeyRpId' => 'lars-pohlmann.de',
            'passkeyRpName' => 'Reader',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->passkeys()->countAll());
    }

    public function testPasskeyRpIdEffectiveReflectsTheStoredOverrideOrTheDerivedHost(): void
    {
        $client = $this->adminClient();

        $client->request('GET', self::SETTINGS);
        self::assertSame('localhost', $this->payload($client)['passkeyRpIdEffective']);

        $client->jsonRequest('PUT', self::SETTINGS, [
            'requireEmailConfirmation' => true,
            'requireApproval' => true,
            'publicBaseUrl' => 'https://example.test',
            'passkeyRpId' => 'example.test',
            'passkeyRpName' => 'Reader',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('example.test', $this->payload($client)['passkeyRpIdEffective']);
    }
}
