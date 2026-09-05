<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\User;
use App\Entity\UserPasskey;
use App\Repository\UserPasskeyRepository;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\PinsPasskeyRelyingParty;
use App\Tests\Support\TogglesPasskeySignIn;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Listing and removing passkeys (#624 Task 8): `GET /api/auth/passkeys` and
 * `DELETE /api/auth/passkeys/{id}`. The lock-out decision itself is
 * PasskeyRemovalPolicyTest's job — this class proves the endpoint wires that
 * policy in, scopes every lookup to the caller, and answers 404 (never 403)
 * for a credential somebody else owns.
 */
final class PasskeyListTest extends ApiTestCase
{
    use TogglesPasskeySignIn;
    use PinsPasskeyRelyingParty;

    /**
     * Pinned so a future addition cannot silently widen the payload: beside
     * the unchanged rows, exactly the three values the Signal API needs (#727).
     */
    public function testListingCarriesTheSignalValuesBesideTheUnchangedRows(): void
    {
        $client = static::createClient();
        $user = $this->factory()->create('lister@example.test');
        $this->givenAPasskeyFor(
            $user,
            credentialId: 'Y3JlZC1hYmM',
            userHandle: 'aGFuZGxl',
            label: 'My phone',
            createdAt: new \DateTimeImmutable('2026-01-01 10:00:00'),
        );
        $this->givenAPasskeyFor(
            $user,
            credentialId: 'c2Vjb25k',
            userHandle: 'aGFuZGxl',
            label: 'YubiKey',
            createdAt: new \DateTimeImmutable('2026-01-02 10:00:00'),
        );
        $this->authenticate($client, 'lister@example.test');
        $this->pinRelyingParty('example.test', 'Example Reader', 'https://example.test');
        $this->serveFrom($client, 'https://example.test');

        $client->request('GET', '/api/auth/passkeys');

        self::assertResponseIsSuccessful();
        $body = $this->payload($client);
        $topLevelKeys = array_keys($body);
        sort($topLevelKeys);
        self::assertSame(['acceptedCredentialIds', 'passkeys', 'rpId', 'userHandle'], $topLevelKeys);
        self::assertSame('example.test', $body['rpId']);
        self::assertSame('aGFuZGxl', $body['userHandle']);
        self::assertSame(['Y3JlZC1hYmM', 'c2Vjb25k'], $body['acceptedCredentialIds']);
        $passkeys = $this->passkeysFromResponse($client);
        self::assertCount(2, $passkeys);
        $rowKeys = array_keys($passkeys[0]);
        sort($rowKeys);
        self::assertSame(['createdAt', 'id', 'label', 'lastUsedAt'], $rowKeys);
        self::assertSame('My phone', $passkeys[0]['label']);
    }

    /** No rows, no handle — never a minted one (see PasskeyJson::listing()). */
    public function testListingReportsNoUserHandleAndNoAcceptedIdsForAnAccountWithoutPasskeys(): void
    {
        $client = static::createClient();
        $this->factory()->create('empty@example.test');
        $this->authenticate($client, 'empty@example.test');
        $this->enablePasskeySignIn();

        $client->request('GET', '/api/auth/passkeys');

        self::assertResponseIsSuccessful();
        $body = $this->payload($client);
        self::assertNull($body['userHandle']);
        self::assertSame([], $body['acceptedCredentialIds']);
        self::assertSame([], $body['passkeys']);
    }

    public function testAUserCannotSeeAnotherUsersCredentialInTheList(): void
    {
        $client = static::createClient();
        $owner = $this->factory()->create('owner@example.test');
        $this->givenAPasskeyFor($owner, credentialId: 'b3duZXItY3JlZA');
        $caller = $this->factory()->create('caller@example.test');
        $this->givenAPasskeyFor($caller, credentialId: 'Y2FsbGVyLWNyZWQ');
        $this->authenticate($client, 'caller@example.test');
        $this->enablePasskeySignIn();

        $client->request('GET', '/api/auth/passkeys');

        self::assertResponseIsSuccessful();
        $passkeys = $this->passkeysFromResponse($client);
        self::assertCount(1, $passkeys);
        self::assertSame($this->onlyStoredPasskeyFor($caller)->getId(), $passkeys[0]['id']);
    }

    public function testDeletingOwnCredentialSucceedsAndReturns204(): void
    {
        $client = static::createClient();
        $user = $this->factory()->create('remover@example.test');
        $this->givenAPasskeyFor($user, credentialId: 'a2VwdC1jcmVk', label: 'Keep');
        $toDelete = $this->givenAPasskeyFor($user, credentialId: 'ZGVsZXRlLWNyZWQ', label: 'Delete me');
        // Captured before the request: the controller's remove() runs on the
        // SAME entity manager this test shares, so Doctrine nulls the id field
        // on this very object once the deletion succeeds.
        $deletedId = (int) $toDelete->getId();
        $this->authenticate($client, 'remover@example.test');

        $client->request('DELETE', \sprintf('/api/auth/passkeys/%d', $deletedId));

        self::assertResponseStatusCodeSame(204);
        /** @var UserPasskeyRepository $repository */
        $repository = self::getContainer()->get(UserPasskeyRepository::class);
        self::assertSame(1, $repository->countForUser($user));
        self::assertNull($repository->find($deletedId));
    }

    /** A 403 would confirm the id belongs to SOME account — see PasskeyRemoval. */
    public function testDeletingAnotherUsersCredentialReturns404NotFound(): void
    {
        $client = static::createClient();
        $owner = $this->factory()->create('owner@example.test');
        $foreignPasskey = $this->givenAPasskeyFor($owner, credentialId: 'b3duZXItY3JlZA');
        $this->factory()->create('caller@example.test');
        $this->authenticate($client, 'caller@example.test');

        $client->request('DELETE', \sprintf('/api/auth/passkeys/%d', (int) $foreignPasskey->getId()));

        $this->assertRejected($client, 404);
        self::assertSame('passkey_not_found', $this->payload($client)['type']);
        /** @var UserPasskeyRepository $repository */
        $repository = self::getContainer()->get(UserPasskeyRepository::class);
        self::assertNotNull($repository->find($foreignPasskey->getId()));
    }

    /**
     * The acceptance criterion, exercised end to end: an account with no
     * password and no linked OAuth identity must not be able to delete its
     * way out of every sign-in method.
     */
    public function testDeletingTheLastCredentialOnAPasswordLessIdentityLessAccountReturns409(): void
    {
        $client = static::createClient();
        $user = $this->factory()->create('oauth-only@example.test');
        $user->setPasswordHash(null, new \DateTimeImmutable());
        $this->em()->flush();
        $onlyPasskey = $this->givenAPasskeyFor($user, credentialId: 'b25seS1jcmVk');
        $this->authenticate($client, 'oauth-only@example.test');

        $client->request('DELETE', \sprintf('/api/auth/passkeys/%d', (int) $onlyPasskey->getId()));

        $this->assertRejected($client, 409);
        /** @var UserPasskeyRepository $repository */
        $repository = self::getContainer()->get(UserPasskeyRepository::class);
        self::assertNotNull($repository->find($onlyPasskey->getId()));
    }

    /**
     * The list endpoint is one of the six #624 follow-up enforces: a
     * disabled instance refuses even a read of the caller's own credentials,
     * the same as it refuses enrolment and login.
     */
    public function testListingRefusesWhenPasskeySignInIsDisabled(): void
    {
        $client = static::createClient();
        $user = $this->factory()->create('list-disabled@example.test');
        $this->givenAPasskeyFor($user, credentialId: 'ZGlzYWJsZWQtY3JlZA');
        $this->authenticate($client, 'list-disabled@example.test');
        $this->disablePasskeySignIn();

        $client->request('GET', '/api/auth/passkeys');

        $this->assertRejected($client, 403);
    }

    /**
     * The judgement call #624 follow-up leaves deliberate: DELETE stays
     * allowed even while sign-in is disabled, so a user can still clean up a
     * credential they can no longer use. Disabling the feature must not trap
     * that credential on the account forever.
     */
    public function testDeletingOwnCredentialStillSucceedsWhenPasskeySignInIsDisabled(): void
    {
        $client = static::createClient();
        $user = $this->factory()->create('delete-while-disabled@example.test');
        $toDelete = $this->givenAPasskeyFor($user, credentialId: 'c3RpbGwtZGVsZXRhYmxl');
        $this->authenticate($client, 'delete-while-disabled@example.test');
        $this->disablePasskeySignIn();

        $client->request('DELETE', \sprintf('/api/auth/passkeys/%d', (int) $toDelete->getId()));

        self::assertResponseStatusCodeSame(204);
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
     * Matches PasskeyRegistrationTest's own convention: $credentialId and
     * $userHandle are stored verbatim rather than through the real
     * registration ceremony, since this suite tests listing and removal, not
     * attestation.
     */
    private function givenAPasskeyFor(
        User $user,
        string $credentialId,
        string $userHandle = 'aGFuZGxl',
        string $label = 'Test key',
        ?\DateTimeImmutable $createdAt = null,
    ): UserPasskey {
        $passkey = new UserPasskey(
            $user,
            $credentialId,
            $userHandle,
            'cHVibGljLWtleQ',
            0,
            null,
            [],
            $label,
            $createdAt ?? new \DateTimeImmutable(),
        );
        $this->em()->persist($passkey);
        $this->em()->flush();

        return $passkey;
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
