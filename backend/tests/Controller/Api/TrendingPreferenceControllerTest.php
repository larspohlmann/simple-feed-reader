<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Tests\Support\ApiTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

final class TrendingPreferenceControllerTest extends ApiTestCase
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
        self::assertFalse($reloaded->getPreferences()->isTrendingEnabled());
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
}
