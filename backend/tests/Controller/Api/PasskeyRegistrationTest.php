<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\User;
use App\Entity\UserPasskey;
use App\Service\Settings\InstanceSettings;
use App\Service\Settings\InstanceSettingsUpdate;
use App\Tests\Support\ApiTestCase;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Issuing WebAuthn registration ("attestation") options (#624): the relying
 * party, the resident-key/user-verification requirements, and the exclude
 * list that stops one authenticator enrolling twice on the same account.
 *
 * Every test that reads `options.rp.id` pins the relying party explicitly via
 * the `instance_setting` row rather than asserting against whatever
 * `APP_FRONTEND_URL` happens to resolve to in this environment — see
 * ConfiguredPasskeyRelyingPartyTest for the same reasoning. A hard-coded
 * `'localhost'` would pass locally for the wrong reason and could fail in CI
 * for one unrelated to this feature.
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
     * round-trips back out through the WebAuthn serializer unchanged.
     */
    private function givenAPasskeyFor(User $user, string $credentialId): void
    {
        $this->em()->persist(new UserPasskey(
            $user,
            $credentialId,
            'aGFuZGxl',
            'cHVibGljLWtleQ',
            0,
            null,
            [],
            'Test key',
            new \DateTimeImmutable(),
        ));
        $this->em()->flush();
    }

    private function pinRelyingParty(string $relyingPartyId, string $relyingPartyName): void
    {
        /** @var InstanceSettings $settings */
        $settings = self::getContainer()->get(InstanceSettings::class);
        $settings->update(new InstanceSettingsUpdate(
            requireEmailConfirmation: true,
            requireApproval: true,
            publicBaseUrl: null,
            passkeyRpId: $relyingPartyId,
            passkeyRpName: $relyingPartyName,
        ));
    }
}
