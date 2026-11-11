<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\StoredUserSettings;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

final class ReaderPreferencesControllerTest extends ApiTestCase
{
    private function patchTrending(KernelBrowser $client, string $body): void
    {
        $client->request(
            'PATCH',
            '/api/me/trending',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $body,
        );
    }

    private function trendingEnabled(KernelBrowser $client): mixed
    {
        $preferences = $this->payload($client)['preferences'];
        self::assertIsArray($preferences);

        return $preferences['trendingEnabled'];
    }

    private function patchScope(KernelBrowser $client, string $body): void
    {
        $client->request(
            'PATCH',
            '/api/me/feed-directory-scope',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $body,
        );
    }

    /** @return array<mixed> */
    private function scope(KernelBrowser $client): array
    {
        $preferences = $this->payload($client)['preferences'];
        self::assertIsArray($preferences);
        self::assertIsArray($preferences['feedDirectoryScope']);

        return $preferences['feedDirectoryScope'];
    }

    public function testScrapeFallbackCanBeEnabled(): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('prefs-enable@example.com');
        $this->authenticate($client, $user);

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
                'trendingEnabled' => true,
                'feedDirectoryScope' => ['countries' => [], 'languages' => []],
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

        $this->entityManager()->clear();
        $reloaded = $this->users()->find($user->getId());
        self::assertInstanceOf(User::class, $reloaded);
        self::assertTrue(StoredUserSettings::reading($this->entityManager(), $reloaded)->isScrapeFallbackEnabled());
    }

    public function testANonBooleanPreferenceIsRejected(): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('prefs-invalid@example.com');
        StoredUserSettings::reading($this->entityManager(), $user)->setScrapeFallbackEnabled(true);
        $this->entityManager()->flush();
        $this->authenticate($client, $user);

        $client->request(
            'PATCH',
            '/api/me/preferences',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['scrapeFallbackEnabled' => 'yes'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->entityManager()->clear();
        $unchanged = $this->users()->find($user->getId());
        self::assertInstanceOf(User::class, $unchanged);
        self::assertTrue(StoredUserSettings::reading($this->entityManager(), $unchanged)->isScrapeFallbackEnabled());
    }

    public function testAnEmptyBodyIsRejectedRatherThanSilentlyDisablingScraping(): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('prefs-empty@example.com');
        StoredUserSettings::reading($this->entityManager(), $user)->setScrapeFallbackEnabled(true);
        $this->entityManager()->flush();
        $this->authenticate($client, $user);

        $client->request(
            'PATCH',
            '/api/me/preferences',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->entityManager()->clear();
        $unchanged = $this->users()->find($user->getId());
        self::assertInstanceOf(User::class, $unchanged);
        self::assertTrue(StoredUserSettings::reading($this->entityManager(), $unchanged)->isScrapeFallbackEnabled());
    }

    public function testANewAccountShowsTrending(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'trending-by-default@example.test');

        $client->request('GET', '/api/me');

        self::assertResponseIsSuccessful();
        self::assertTrue($this->trendingEnabled($client));
    }

    public function testSwitchingTrendingOffPersistsIt(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'no-trending@example.test');

        $this->patchTrending($client, '{"trendingEnabled":false}');

        self::assertResponseIsSuccessful();
        self::assertFalse($this->trendingEnabled($client));

        $this->entityManager()->clear();
        $reloaded = $this->users()->findOneBy(['email' => 'no-trending@example.test']);
        self::assertNotNull($reloaded);
        self::assertFalse(StoredUserSettings::reading($this->entityManager(), $reloaded)->isTrendingEnabled());
    }

    public function testSwitchingTrendingBackOnPersistsIt(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'trending-again@example.test');
        $this->patchTrending($client, '{"trendingEnabled":false}');

        $this->patchTrending($client, '{"trendingEnabled":true}');

        self::assertResponseIsSuccessful();
        self::assertTrue($this->trendingEnabled($client));
    }

    public function testAnEmptyBodyIsRejectedRatherThanSilentlySwitchingTrendingOff(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'trending-empty@example.test');

        $this->patchTrending($client, '{}');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testANonBooleanIsRejected(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'trending-yes@example.test');

        $this->patchTrending($client, '{"trendingEnabled":"no"}');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testASignedOutClientIsRejected(): void
    {
        $client = self::createClient();

        $this->patchTrending($client, '{"trendingEnabled":false}');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testAPreferencesWriteLeavesTrendingAlone(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'trending-independent@example.test');
        $this->patchTrending($client, '{"trendingEnabled":false}');

        $client->request(
            'PATCH',
            '/api/me/preferences',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"scrapeFallbackEnabled":true}',
        );

        self::assertResponseIsSuccessful();
        self::assertFalse($this->trendingEnabled($client));
    }

    public function testANewAccountHasNoFeedDirectoryScope(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'scope-new@example.test');

        $client->request('GET', '/api/me');

        self::assertSame(['countries' => [], 'languages' => []], $this->scope($client));
    }

    public function testAScopeIsSavedAndReadBack(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'scope-save@example.test');

        $this->patchScope($client, '{"countries":["DE","AT"],"languages":["de"]}');

        self::assertResponseIsSuccessful();
        self::assertSame(['countries' => ['DE', 'AT'], 'languages' => ['de']], $this->scope($client));
    }

    public function testTwoEmptyListsClearTheScope(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'scope-clear@example.test');
        $this->patchScope($client, '{"countries":["DE"],"languages":["de"]}');

        $this->patchScope($client, '{"countries":[],"languages":[]}');

        self::assertSame(['countries' => [], 'languages' => []], $this->scope($client));
    }

    public function testCountriesWithoutLanguagesAreRejected(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'scope-half@example.test');

        $this->patchScope($client, '{"countries":["DE"],"languages":[]}');

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testAnUnknownCountryIsRejected(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'scope-unknown@example.test');

        $this->patchScope($client, '{"countries":["FR"],"languages":["de"]}');

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testAnUnknownLanguageIsRejected(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'scope-language@example.test');

        $this->patchScope($client, '{"countries":["DE"],"languages":["xx"]}');

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testAnEmptyBodyIsRejectedRatherThanClearingTheScope(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'scope-empty@example.test');

        $this->patchScope($client, '{}');

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
