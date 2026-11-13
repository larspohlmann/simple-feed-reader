<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\StoredUserSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

final class ReaderPreferencesControllerTest extends ApiTestCase
{
    private const array READING_DEFAULTS = [
        'scrapeFallbackEnabled' => false,
        'magazineStyle' => 'boxed',
        'handedness' => 'right',
        'trendingEnabled' => true,
    ];

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

    private function patchPreferences(KernelBrowser $client, string $body): void
    {
        $client->request(
            'PATCH',
            '/api/me/preferences',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $body,
        );
    }

    /** @return array<string, mixed> */
    private function readingSettings(KernelBrowser $client): array
    {
        $client->request('GET', '/api/me');
        $preferences = $this->payload($client)['preferences'];
        self::assertIsArray($preferences);

        return [
            'scrapeFallbackEnabled' => $preferences['scrapeFallbackEnabled'],
            'magazineStyle' => $preferences['magazineStyle'],
            'handedness' => $preferences['handedness'],
            'trendingEnabled' => $preferences['trendingEnabled'],
        ];
    }

    public function testScrapeFallbackCanBeEnabled(): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('prefs-enable@example.com');
        $this->authenticate($client, $user);

        $this->patchPreferences($client, '{"scrapeFallbackEnabled":true}');

        self::assertResponseIsSuccessful();
        self::assertSame(
            [
                'scrapeFallbackEnabled' => true,
                'magazineStyle' => 'boxed',
                'handedness' => 'right',
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

    /** @return iterable<string, array{string, mixed}> */
    public static function readingSettingChangedAlone(): iterable
    {
        yield 'scrape fallback' => ['scrapeFallbackEnabled', true];
        yield 'magazine style' => ['magazineStyle', 'airy'];
        yield 'handedness' => ['handedness', 'left'];
        yield 'trending' => ['trendingEnabled', false];
    }

    #[DataProvider('readingSettingChangedAlone')]
    public function testEachSettingChangesAlone(string $key, mixed $value): void
    {
        $client = self::createClient();
        $this->signIn($client, 'prefs-alone-' . $key . '@example.test');
        $this->patchPreferences($client, json_encode([$key => $value], \JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        self::assertSame(array_replace(self::READING_DEFAULTS, [$key => $value]), $this->readingSettings($client));
    }

    public function testTwoSettingsChangeInOneWrite(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'prefs-two@example.test');

        $this->patchPreferences($client, '{"handedness":"left","trendingEnabled":false}');

        self::assertResponseIsSuccessful();
        self::assertSame(
            array_replace(self::READING_DEFAULTS, ['handedness' => 'left', 'trendingEnabled' => false]),
            $this->readingSettings($client),
        );
    }

    public function testAnEmptyBodyIsRejected(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'prefs-empty-body@example.test');

        $this->patchPreferences($client, '{}');

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testANullOnlyBodyIsRejected(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'prefs-null-only@example.test');

        $this->patchPreferences($client, '{"handedness":null}');

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testAnUnknownKeyIsRejected(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'prefs-unknown-key@example.test');

        $this->patchPreferences($client, '{"handedness":"left","fontSize":3}');

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('right', $this->readingSettings($client)['handedness']);
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedReadingSettingBodies(): iterable
    {
        yield 'a string for scrape fallback' => ['{"scrapeFallbackEnabled":"yes"}'];
        yield 'a string for trending' => ['{"trendingEnabled":"false"}'];
        yield 'an unknown magazine style' => ['{"magazineStyle":"cards"}'];
        yield 'an unknown handedness' => ['{"handedness":"both"}'];
    }

    #[DataProvider('rejectedReadingSettingBodies')]
    public function testAnInvalidValueIsRejectedAndNothingChanges(string $body): void
    {
        $client = self::createClient();
        $this->signIn($client, 'prefs-invalid-value-' . md5($body) . '@example.test');

        $this->patchPreferences($client, $body);

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(self::READING_DEFAULTS, $this->readingSettings($client));
    }

    public function testASignedOutPreferencesWriteIsRejected(): void
    {
        $client = self::createClient();

        $this->patchPreferences($client, '{"trendingEnabled":false}');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testANewAccountShowsTrending(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'trending-by-default@example.test');

        self::assertTrue($this->readingSettings($client)['trendingEnabled']);
    }

    public function testSwitchingTrendingBackOnPersistsIt(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'trending-again@example.test');
        $this->patchPreferences($client, '{"trendingEnabled":false}');

        $this->patchPreferences($client, '{"trendingEnabled":true}');

        self::assertResponseIsSuccessful();
        self::assertTrue($this->readingSettings($client)['trendingEnabled']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function retiredSettingRoutes(): iterable
    {
        yield 'magazine style' => ['/api/me/magazine-style', 'retired-magazine-style@example.test'];
        yield 'trending' => ['/api/me/trending', 'retired-trending@example.test'];
        yield 'handedness' => ['/api/me/handedness', 'retired-handedness@example.test'];
    }

    #[DataProvider('retiredSettingRoutes')]
    public function testTheRetiredSettingRoutesAreGone(string $path, string $email): void
    {
        $client = self::createClient();
        $this->signIn($client, $email);

        $client->request(
            'PATCH',
            $path,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"magazineStyle":"airy","trendingEnabled":false,"handedness":"left"}',
        );

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
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
