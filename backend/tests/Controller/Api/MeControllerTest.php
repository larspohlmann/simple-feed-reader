<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\EnablesMailInTests;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

/** Tokens come straight from the JWT manager, so no case touches, or is poisoned by, the login throttler's pool. */
final class MeControllerTest extends ApiTestCase
{
    use EnablesMailInTests;

    public function testTheProfileCarriesTheAccountLocale(): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('locale-reader@example.test');
        $user->setLocale('de');
        $this->entityManager()->flush();

        $this->authenticate($client, $user);
        $client->request('GET', '/api/me');

        self::assertResponseIsSuccessful();
        self::assertSame('de', $this->payload($client)['locale']);
    }

    public function testChangingTheLocalePersistsIt(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'switcher@example.test');

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
        $client = self::createClient();
        $this->signIn($client, 'klingon@example.test');

        $client->request(
            'PATCH',
            '/api/me',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"locale":"tlh"}',
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
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
        $client = self::createClient();
        $client->request(
            'PATCH',
            '/api/me',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"locale":"de"}',
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testTrialEndsAtIsExposedWhenSet(): void
    {
        // The date must stay in the future against the real system clock: an
        // account whose trial has ended is suspended by TrialExpiryGuard on its
        // next request and can no longer authenticate.
        $trialEndsAt = new \DateTimeImmutable('2999-01-01T00:00:00Z');

        $client = self::createClient();
        $user = $this->factory()->create('has-trial@example.test', trialEndsAt: $trialEndsAt);
        $this->authenticate($client, $user);

        $client->request('GET', '/api/me');

        self::assertResponseIsSuccessful();
        self::assertSame(
            $trialEndsAt->format(\DateTimeInterface::ATOM),
            $this->payload($client)['trialEndsAt'],
        );
    }

    public function testTrialEndsAtIsNullWhenUnset(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'no-trial@example.test');

        $client->request('GET', '/api/me');

        self::assertResponseIsSuccessful();
        self::assertNull($this->payload($client)['trialEndsAt']);
    }

    public function testTheProfileCarriesMailCapabilityAndVerificationState(): void
    {
        $client = self::createClient();
        $this->seedEnabledMailInstance();
        $this->signIn($client, 'mail-flags@example.test');

        $client->request('GET', '/api/me');

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client);
        self::assertSame(['enabled' => true], $payload['mail']);
        self::assertFalse($payload['emailVerified']);
    }

    public function testTheProfileSaysWhetherTheCatalogOffersAFeed(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'catalog-flag@example.test');

        $client->request('GET', '/api/me');
        self::assertResponseIsSuccessful();
        self::assertSame(['hasEntries' => false], $this->payload($client)['catalog']);

        $category = new CatalogCategory('technology', 'Technology', 'memory', '#3b82f6');
        $this->entityManager()->persist($category);
        $this->entityManager()->persist(
            new CatalogFeed($category, 'The Verge', 'https://www.theverge.com/rss/index.xml'),
        );
        $this->entityManager()->flush();

        $client->request('GET', '/api/me');
        self::assertResponseIsSuccessful();
        self::assertSame(['hasEntries' => true], $this->payload($client)['catalog']);
    }

    public function testTheProfileCarriesPreferencesWithScrapingOffByDefault(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'prefs-default@example.com');

        $client->request('GET', '/api/me');

        self::assertResponseIsSuccessful();
        self::assertSame(
            [
                'scrapeFallbackEnabled' => false,
                'magazineStyle' => 'boxed',
                'passkeyOfferAnswered' => false,
                'trendingEnabled' => true,
                'sharing' => ['enabled' => true, 'hiddenTargets' => [], 'rememberShares' => true],
                'digest' => [
                    'enabled' => false,
                    'cadence' => 'daily',
                    'format' => 'html',
                    'sendHour' => 8,
                    'weekday' => 1,
                    'timezone' => 'UTC',
                ],
            ],
            $this->payload($client)['preferences'],
        );
    }

    public function testThePreferencesObjectKeepsItsShapeForEveryStoredSetting(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'every-setting@example.test');

        $client->request('POST', '/api/me/passkey-offer/answer');
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->patch($client, '/api/me/preferences', ['scrapeFallbackEnabled' => true]);
        $this->patch($client, '/api/me/magazine-style', ['magazineStyle' => 'airy']);
        $this->patch($client, '/api/me/trending', ['trendingEnabled' => false]);
        $this->patch(
            $client,
            '/api/me/sharing',
            ['enabled' => false, 'hiddenTargets' => ['x', 'email'], 'rememberShares' => false],
        );
        $this->patch(
            $client,
            '/api/me/digest',
            ['enabled' => true, 'cadence' => 'weekly', 'sendHour' => 9, 'weekday' => 3, 'format' => 'text'],
        );
        $expected = '{"scrapeFallbackEnabled":true,"magazineStyle":"airy","passkeyOfferAnswered":true,'
            . '"trendingEnabled":false,'
            . '"sharing":{"enabled":false,"hiddenTargets":["x","email"],"rememberShares":false},'
            . '"digest":{"enabled":true,"cadence":"weekly","format":"text","sendHour":9,"weekday":3,"timezone":"UTC"}}';
        self::assertSame($expected, json_encode($this->payload($client)['preferences'], \JSON_THROW_ON_ERROR));

        $client->request('GET', '/api/me');

        self::assertResponseIsSuccessful();
        self::assertSame($expected, json_encode($this->payload($client)['preferences'], \JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $body */
    private function patch(KernelBrowser $client, string $uri, array $body): void
    {
        $client->request(
            'PATCH',
            $uri,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();
    }

    public function testAUserDeletesTheirOwnAccount(): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('bye@example.com');
        $userId = $user->requireId();
        $this->authenticate($client, $user);

        $client->request('DELETE', '/api/me');
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        // The JWT is stateless: this proves the token stops authenticating
        // because the user row is gone, not because anything was revoked.
        $client->request('GET', '/api/me');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        self::assertNull($this->users()->find($userId));
    }

    public function testTheSoleAdminCannotDeleteTheirOwnAccount(): void
    {
        $client = self::createClient();
        $admin = $this->factory()->create('last-admin@example.com', roles: ['ROLE_ADMIN']);
        $this->authenticate($client, $admin);

        $client->request('DELETE', '/api/me');

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
    }

    /** With the other admin suspended, the last active admin's self-delete must be refused: nobody could reinstate. */
    public function testTheLastActiveAdminCannotSelfDeleteAfterSuspendingTheOtherAdmin(): void
    {
        $client = self::createClient();
        $admin = $this->factory()->create('remaining-admin@example.com', roles: ['ROLE_ADMIN']);
        $suspended = $this->factory()->create('to-be-suspended-admin@example.com', roles: ['ROLE_ADMIN']);
        $this->authenticate($client, $admin);

        $client->request('POST', '/api/admin/users/' . $suspended->getId() . '/suspend');
        self::assertResponseIsSuccessful();

        $client->request('DELETE', '/api/me');

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
    }

    public function testDeletingTheAccountNeedsAToken(): void
    {
        $client = self::createClient();
        $client->request('DELETE', '/api/me');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }
}
