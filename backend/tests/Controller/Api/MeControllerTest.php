<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The account's own view of, and write path onto, its locale. Tokens are
 * minted straight from the JWT manager rather than through POST
 * /api/auth/login, so these cases never touch the login throttler's
 * filesystem pool and cannot be poisoned by it.
 */
final class MeControllerTest extends ApiTestCase
{
    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        return $em;
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

    public function testTheProfileCarriesTheAccountLocale(): void
    {
        $client = static::createClient();
        $user = $this->factory()->create('locale-reader@example.test');
        $user->setLocale('de');
        $this->entityManager()->flush();

        $this->authenticate($client, 'locale-reader@example.test');
        $client->request('GET', '/api/me');

        self::assertResponseIsSuccessful();
        self::assertSame('de', $this->payload($client)['locale']);
    }

    public function testChangingTheLocalePersistsIt(): void
    {
        $client = static::createClient();
        $this->factory()->create('switcher@example.test');
        $this->authenticate($client, 'switcher@example.test');

        $client->request(
            'PATCH',
            '/api/me',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"locale":"de"}',
        );

        self::assertResponseIsSuccessful();
        self::assertSame('de', $this->payload($client)['locale']);

        $this->entityManager()->clear();
        $reloaded = $this->users()->findOneBy(['email' => 'switcher@example.test']);
        self::assertNotNull($reloaded);
        self::assertSame('de', $reloaded->getLocale());
    }

    public function testAnUnsupportedLocaleIsRejected(): void
    {
        $client = static::createClient();
        $this->factory()->create('klingon@example.test');
        $this->authenticate($client, 'klingon@example.test');

        $client->request(
            'PATCH',
            '/api/me',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"locale":"tlh"}',
        );

        self::assertResponseStatusCodeSame(422);
        self::assertStringStartsWith(
            'application/problem+json',
            (string) $client->getResponse()->headers->get('Content-Type'),
        );

        $this->entityManager()->clear();
        $unchanged = $this->users()->findOneBy(['email' => 'klingon@example.test']);
        self::assertNotNull($unchanged);
        self::assertSame('en', $unchanged->getLocale());
    }

    public function testChangingTheLocaleRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request(
            'PATCH',
            '/api/me',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"locale":"de"}',
        );

        self::assertResponseStatusCodeSame(401);
    }

    public function testTrialEndsAtIsExposedWhenSet(): void
    {
        $client = static::createClient();
        $this->factory()->create(
            'has-trial@example.test',
            trialEndsAt: new \DateTimeImmutable('2026-09-01T00:00:00Z'),
        );
        $this->authenticate($client, 'has-trial@example.test');

        $client->request('GET', '/api/me');

        self::assertResponseIsSuccessful();
        self::assertSame('2026-09-01T00:00:00+00:00', $this->payload($client)['trialEndsAt']);
    }

    public function testTrialEndsAtIsNullWhenUnset(): void
    {
        $client = static::createClient();
        $this->factory()->create('no-trial@example.test');
        $this->authenticate($client, 'no-trial@example.test');

        $client->request('GET', '/api/me');

        self::assertResponseIsSuccessful();
        self::assertNull($this->payload($client)['trialEndsAt']);
    }

    public function testTheProfileCarriesMailCapabilityAndVerificationState(): void
    {
        $client = static::createClient();
        $this->factory()->create('mail-flags@example.test');
        $this->authenticate($client, 'mail-flags@example.test');

        $client->request('GET', '/api/me');

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client);
        self::assertSame(['enabled' => true], $payload['mail']);
        self::assertFalse($payload['emailVerified']);
    }

    public function testScrapeFallbackCanBeEnabled(): void
    {
        $client = static::createClient();
        $user = $this->factory()->create('prefs-enable@example.com');
        $this->authenticate($client, 'prefs-enable@example.com');

        $client->request(
            'PATCH',
            '/api/me/preferences',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['scrapeFallbackEnabled' => true], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
        self::assertSame(
            [
                'scrapeFallbackEnabled' => true,
                'magazineStyle' => 'boxed',
                'passkeyOfferAnswered' => false,
                'digest' => [
                    'enabled' => false,
                    'cadence' => 'daily',
                    'sendHour' => 8,
                    'weekday' => 1,
                    'timezone' => 'UTC',
                ],
            ],
            $this->payload($client)['preferences'],
        );

        $this->entityManager()->clear();
        $reloaded = $this->users()->find($user->getId());
        self::assertInstanceOf(User::class, $reloaded);
        self::assertTrue($reloaded->getPreferences()->isScrapeFallbackEnabled());
    }

    public function testANonBooleanPreferenceIsRejected(): void
    {
        $client = static::createClient();
        $user = $this->factory()->create('prefs-invalid@example.com');
        $user->getPreferences()->setScrapeFallbackEnabled(true);
        $this->entityManager()->flush();
        $this->authenticate($client, 'prefs-invalid@example.com');

        $client->request(
            'PATCH',
            '/api/me/preferences',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['scrapeFallbackEnabled' => 'yes'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(422);

        $this->entityManager()->clear();
        $unchanged = $this->users()->find($user->getId());
        self::assertInstanceOf(User::class, $unchanged);
        self::assertTrue($unchanged->getPreferences()->isScrapeFallbackEnabled());
    }

    public function testAnEmptyBodyIsRejectedRatherThanSilentlyDisablingScraping(): void
    {
        $client = static::createClient();
        $user = $this->factory()->create('prefs-empty@example.com');
        $user->getPreferences()->setScrapeFallbackEnabled(true);
        $this->entityManager()->flush();
        $this->authenticate($client, 'prefs-empty@example.com');

        $client->request(
            'PATCH',
            '/api/me/preferences',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(422);

        $this->entityManager()->clear();
        $unchanged = $this->users()->find($user->getId());
        self::assertInstanceOf(User::class, $unchanged);
        self::assertTrue($unchanged->getPreferences()->isScrapeFallbackEnabled());
    }

    public function testTheProfileCarriesPreferencesWithScrapingOffByDefault(): void
    {
        $client = static::createClient();
        $this->factory()->create('prefs-default@example.com');
        $this->authenticate($client, 'prefs-default@example.com');

        $client->request('GET', '/api/me');

        self::assertResponseIsSuccessful();
        self::assertSame(
            [
                'scrapeFallbackEnabled' => false,
                'magazineStyle' => 'boxed',
                'passkeyOfferAnswered' => false,
                'digest' => [
                    'enabled' => false,
                    'cadence' => 'daily',
                    'sendHour' => 8,
                    'weekday' => 1,
                    'timezone' => 'UTC',
                ],
            ],
            $this->payload($client)['preferences'],
        );
    }

    public function testAUserDeletesTheirOwnAccount(): void
    {
        $client = static::createClient();
        $user = $this->factory()->create('bye@example.com');
        $userId = (int) $user->getId();
        $this->authenticate($client, 'bye@example.com');

        $client->request('DELETE', '/api/me');
        self::assertResponseStatusCodeSame(204);

        // The JWT is stateless: this proves the token stops authenticating
        // because the user row is gone, not because anything was revoked.
        $client->request('GET', '/api/me');
        self::assertResponseStatusCodeSame(401);

        self::assertNull($this->users()->find($userId));
    }

    public function testTheSoleAdminCannotDeleteTheirOwnAccount(): void
    {
        $client = static::createClient();
        $this->factory()->create('last-admin@example.com', roles: ['ROLE_ADMIN']);
        $this->authenticate($client, 'last-admin@example.com');

        $client->request('DELETE', '/api/me');

        self::assertResponseStatusCodeSame(409);
    }

    /**
     * The lockout chain the status-blind countAdmins() used to miss: two
     * active admins both count, but suspending one leaves only one admin able
     * to act at all. countActiveAdmins() must refuse the remaining admin's
     * self-delete here, or the instance is left with a suspended admin nobody
     * can reinstate (approve sits behind ROLE_ADMIN on ^/api/admin/).
     */
    public function testTheLastActiveAdminCannotSelfDeleteAfterSuspendingTheOtherAdmin(): void
    {
        $client = static::createClient();
        $this->factory()->create('remaining-admin@example.com', roles: ['ROLE_ADMIN']);
        $suspended = $this->factory()->create('to-be-suspended-admin@example.com', roles: ['ROLE_ADMIN']);
        $this->authenticate($client, 'remaining-admin@example.com');

        $client->request('POST', '/api/admin/users/' . $suspended->getId() . '/suspend');
        self::assertResponseIsSuccessful();

        $client->request('DELETE', '/api/me');

        self::assertResponseStatusCodeSame(409);
    }

    public function testDeletingTheAccountNeedsAToken(): void
    {
        $client = static::createClient();
        $client->request('DELETE', '/api/me');

        self::assertResponseStatusCodeSame(401);
    }
}
