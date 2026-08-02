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

    public function testTheProfileCarriesPreferencesWithScrapingOffByDefault(): void
    {
        $client = static::createClient();
        $this->factory()->create('prefs-default@example.com');
        $this->authenticate($client, 'prefs-default@example.com');

        $client->request('GET', '/api/me');

        self::assertResponseIsSuccessful();
        self::assertSame(['scrapeFallbackEnabled' => false], $this->payload($client)['preferences']);
    }
}
