<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\User;
use App\Entity\UserPasskey;
use App\Repository\UserPasskeyRepository;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\EnablesMailInTests;
use App\Tests\Support\PasskeyRegistrations;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
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
    use EnablesMailInTests;

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
        $this->seedEnabledMailInstance();
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

    /** The relying party is judged against the host the request arrived on, so
     *  a test about relying-party ids has to say which host it is on. */
    private function servedFrom(KernelBrowser $client, string $host): KernelBrowser
    {
        $client->setServerParameter('HTTP_HOST', $host);

        return $client;
    }

    private function passkeys(): UserPasskeyRepository
    {
        /** @var UserPasskeyRepository $repository */
        $repository = self::getContainer()->get(UserPasskeyRepository::class);

        return $repository;
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function settingsBody(array $changes = []): array
    {
        return [
            'requireEmailConfirmation' => true,
            'requireApproval' => true,
            'publicBaseUrl' => null,
            'passkeyRpId' => null,
            'passkeyRpName' => null,
            'passkeySignInEnabled' => false,
            ...$changes,
        ];
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
            registration: PasskeyRegistrations::any(
                credentialId: bin2hex(random_bytes(16)),
                userHandle: bin2hex(random_bytes(16)),
                label: 'Test passkey',
                registeredAt: new \DateTimeImmutable('2026-08-29 10:00:00'),
            ),
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
                'publicBaseUrlDefault' => 'http://localhost:4200',
                'passkeyRpId' => null,
                'passkeyRpName' => null,
                // Derived from APP_FRONTEND_URL (http://localhost:4200) in the test env.
                'passkeyRpIdEffective' => 'localhost',
                // Off by default (#624 follow-up, addendum): a fresh install
                // ships with passkey sign-in invisible until an admin opts in.
                'passkeySignInEnabled' => false,
            ],
            $this->payload($client),
        );
    }

    public function testPutUpdatesTheToggles(): void
    {
        $client = $this->adminClient();

        $client->jsonRequest('PUT', self::SETTINGS, $this->settingsBody(['requireEmailConfirmation' => false]));

        self::assertResponseIsSuccessful();
        self::assertSame(
            [
                'requireEmailConfirmation' => false,
                'requireApproval' => true,
                'mailEnabled' => true,
                'publicBaseUrl' => null,
                'publicBaseUrlDefault' => 'http://localhost:4200',
                'passkeyRpId' => null,
                'passkeyRpName' => null,
                'passkeyRpIdEffective' => 'localhost',
                'passkeySignInEnabled' => false,
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
                'publicBaseUrlDefault' => 'http://localhost:4200',
                'passkeyRpId' => null,
                'passkeyRpName' => null,
                'passkeyRpIdEffective' => 'localhost',
                'passkeySignInEnabled' => false,
            ],
            $this->payload($client),
        );
    }

    public function testPutPersistsThePublicBaseUrl(): void
    {
        $client = $this->adminClient();

        $client->jsonRequest(
            'PUT',
            self::SETTINGS,
            $this->settingsBody(['publicBaseUrl' => 'https://reader.example.ts.net/reader']),
        );

        self::assertResponseIsSuccessful();
        self::assertSame('https://reader.example.ts.net/reader', $this->payload($client)['publicBaseUrl']);
    }

    public function testPutRejectsAMalformedPublicBaseUrl(): void
    {
        $client = $this->adminClient();

        $client->jsonRequest('PUT', self::SETTINGS, $this->settingsBody(['publicBaseUrl' => 'not a url']));

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

    public function testPutRejectsANonBooleanPayload(): void
    {
        $client = $this->adminClient();

        $client->jsonRequest('PUT', self::SETTINGS, $this->settingsBody(['requireEmailConfirmation' => 'nope']));

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

    public function testPutRefusesABodyThatLeavesASettingOutAndKeepsIt(): void
    {
        $client = $this->adminClient();
        $client->jsonRequest('PUT', self::SETTINGS, $this->settingsBody(['passkeySignInEnabled' => true]));
        self::assertResponseIsSuccessful();

        $incomplete = $this->settingsBody();
        unset($incomplete['passkeySignInEnabled']);
        $client->jsonRequest('PUT', self::SETTINGS, $incomplete);

        self::assertResponseStatusCodeSame(422);
        $problem = $this->payload($client);
        self::assertSame('validation_error', $problem['type']);
        self::assertIsArray($problem['errors']);
        self::assertSame(['passkeySignInEnabled'], array_keys($problem['errors']));

        $client->request('GET', self::SETTINGS);
        self::assertTrue($this->payload($client)['passkeySignInEnabled']);
    }

    public function testPutRefusesABodyThatLeavesANullableSettingOutAndKeepsIt(): void
    {
        $client = $this->adminClient();
        $client->jsonRequest('PUT', self::SETTINGS, $this->settingsBody(['publicBaseUrl' => 'https://kept.example']));
        self::assertResponseIsSuccessful();

        $incomplete = $this->settingsBody();
        unset($incomplete['publicBaseUrl']);
        $client->jsonRequest('PUT', self::SETTINGS, $incomplete);

        self::assertResponseStatusCodeSame(422);
        $problem = $this->payload($client);
        self::assertIsArray($problem['errors']);
        self::assertSame(['publicBaseUrl'], array_keys($problem['errors']));

        $client->request('GET', self::SETTINGS);
        self::assertSame('https://kept.example', $this->payload($client)['publicBaseUrl']);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function settingsBodyKeys(): iterable
    {
        yield 'requireEmailConfirmation' => ['requireEmailConfirmation'];
        yield 'requireApproval' => ['requireApproval'];
        yield 'publicBaseUrl' => ['publicBaseUrl'];
        yield 'passkeyRpId' => ['passkeyRpId'];
        yield 'passkeyRpName' => ['passkeyRpName'];
        yield 'passkeySignInEnabled' => ['passkeySignInEnabled'];
    }

    #[DataProvider('settingsBodyKeys')]
    public function testPutRefusesABodyMissingAnySingleSetting(string $key): void
    {
        $client = $this->adminClient();
        $incomplete = $this->settingsBody();
        unset($incomplete[$key]);

        $client->jsonRequest('PUT', self::SETTINGS, $incomplete);

        self::assertResponseStatusCodeSame(422);
        $problem = $this->payload($client);
        self::assertIsArray($problem['errors']);
        self::assertSame([$key], array_keys($problem['errors']));
    }

    public function testNonAdminIsForbidden(): void
    {
        $client = $this->authenticateAs($this->factory()->create('plain@example.com'));

        $client->request('GET', self::SETTINGS);

        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

    public function testARelyingPartyIdThatCouldNeverWorkIsRefused(): void
    {
        $client = $this->adminClient();

        $client->jsonRequest('PUT', self::SETTINGS, $this->settingsBody([
            'publicBaseUrl' => 'https://example.test',
            'passkeyRpId' => '203.0.113.5',
            'passkeyRpName' => 'Reader',
        ]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testChangingTheRelyingPartyIdIsRefusedWhileCredentialsExist(): void
    {
        $this->givenAnEnrolledPasskey();
        $client = $this->servedFrom($this->adminClient(), 'a.example.test');

        $client->jsonRequest('PUT', self::SETTINGS, $this->settingsBody([
            'passkeyRpId' => 'example.test',
            'passkeyRpName' => 'Reader',
        ]));

        self::assertResponseStatusCodeSame(409);
        self::assertSame(1, $this->payload($client)['invalidatedPasskeyCount']);
    }

    public function testAConfirmedChangeDeletesEveryCredential(): void
    {
        $this->givenAnEnrolledPasskey();
        $client = $this->servedFrom($this->adminClient(), 'a.example.test');

        $client->jsonRequest('PUT', self::SETTINGS, $this->settingsBody([
            'passkeyRpId' => 'example.test',
            'passkeyRpName' => 'Reader',
            'invalidateExistingPasskeys' => true,
        ]));

        self::assertResponseIsSuccessful();
        // A fresh repository read, not an entity handle: after a bulk DELETE,
        // find() would serve the stale identity map instead of the real state.
        self::assertSame(0, $this->passkeys()->countAll());
    }

    /**
     * The reported case: the reader is reached through a proxy on one host
     * while APP_FRONTEND_URL still names another. Judging the id by the public
     * base URL refused the only id that could ever have worked here.
     */
    public function testTheProxiedHostIsAcceptedWhileThePublicBaseUrlNamesAnother(): void
    {
        $client = $this->servedFrom($this->adminClient(), 'reader.example.ts.net');

        $client->jsonRequest('PUT', self::SETTINGS, $this->settingsBody([
            'publicBaseUrl' => 'http://localhost:4200',
            'passkeyRpId' => 'reader.example.ts.net',
            'passkeyRpName' => 'Reader',
        ]));

        self::assertResponseIsSuccessful();
        self::assertSame('reader.example.ts.net', $this->payload($client)['passkeyRpIdEffective']);
    }

    /**
     * publicBaseUrl is where email links point; it is not an origin. Moving it
     * must leave the relying party — and every enrolled passkey — where it was.
     */
    public function testChangingPublicBaseUrlAloneLeavesTheRelyingPartyAlone(): void
    {
        $client = $this->servedFrom($this->adminClient(), 'a.example.test');

        $client->jsonRequest('PUT', self::SETTINGS, $this->settingsBody(['publicBaseUrl' => 'https://a.example']));
        self::assertResponseIsSuccessful();

        $this->givenAnEnrolledPasskey();

        $client->jsonRequest('PUT', self::SETTINGS, $this->settingsBody(['publicBaseUrl' => 'https://b.example']));

        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->passkeys()->countAll());
    }

    /**
     * The case that proves this guard is not merely "any settings change":
     * publicBaseUrl moves to an unrelated host under the same registrable
     * suffix, but passkeyRpId is explicitly pinned throughout, so the
     * effective id never moves and no confirmation is required.
     */
    public function testPinningTheRelyingPartyIdSurvivesAPublicBaseUrlChangeWithNoConfirmation(): void
    {
        $client = $this->servedFrom($this->adminClient(), 'a.example.test');

        $client->jsonRequest('PUT', self::SETTINGS, $this->settingsBody([
            'publicBaseUrl' => 'https://a.example.test',
            'passkeyRpId' => 'example.test',
            'passkeyRpName' => 'Reader',
        ]));
        self::assertResponseIsSuccessful();

        $this->givenAnEnrolledPasskey();

        $client->jsonRequest('PUT', self::SETTINGS, $this->settingsBody([
            'publicBaseUrl' => 'https://b.example.test',
            'passkeyRpId' => 'example.test',
            'passkeyRpName' => 'Reader',
        ]));

        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->passkeys()->countAll());
    }

    /**
     * Only a CHANGE is guarded — resending the id already in effect must
     * always succeed, or an admin with an enrolled passkey could never touch
     * the other three fields again.
     */
    public function testResendingTheSameRelyingPartyIdSucceedsWithCredentialsPresent(): void
    {
        $client = $this->servedFrom($this->adminClient(), 'a.example.test');
        $body = $this->settingsBody(['passkeyRpId' => 'example.test', 'passkeyRpName' => 'Reader']);

        $client->jsonRequest('PUT', self::SETTINGS, $body);
        self::assertResponseIsSuccessful();

        $this->givenAnEnrolledPasskey();

        $client->jsonRequest('PUT', self::SETTINGS, $body);

        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->passkeys()->countAll());
    }

    /**
     * The instance-wide passkey sign-in switch (#624 follow-up, addendum):
     * off by default — a fresh install ships with the feature invisible
     * until an admin opts in — and a PUT that turns it on round-trips on the
     * next GET.
     */
    public function testPasskeySignInEnabledDefaultsToFalseAndRoundTrips(): void
    {
        $client = $this->adminClient();

        $client->request('GET', self::SETTINGS);
        self::assertFalse($this->payload($client)['passkeySignInEnabled']);

        $client->jsonRequest('PUT', self::SETTINGS, $this->settingsBody(['passkeySignInEnabled' => true]));

        self::assertResponseIsSuccessful();
        self::assertTrue($this->payload($client)['passkeySignInEnabled']);

        $client->request('GET', self::SETTINGS);
        self::assertTrue($this->payload($client)['passkeySignInEnabled']);
    }

    public function testPasskeyRpIdEffectiveReflectsTheStoredOverrideOrTheServingHost(): void
    {
        $client = $this->servedFrom($this->adminClient(), 'a.example.test');

        $client->request('GET', self::SETTINGS);
        self::assertSame('a.example.test', $this->payload($client)['passkeyRpIdEffective']);

        $client->jsonRequest('PUT', self::SETTINGS, $this->settingsBody([
            'passkeyRpId' => 'example.test',
            'passkeyRpName' => 'Reader',
        ]));

        self::assertResponseIsSuccessful();
        self::assertSame('example.test', $this->payload($client)['passkeyRpIdEffective']);
    }
}
