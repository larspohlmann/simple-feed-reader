<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Enum\MagazineStyle;
use App\Tests\Support\ApiTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class MagazineStyleControllerTest extends ApiTestCase
{
    private function patchStyle(KernelBrowser $client, string $style): void
    {
        $client->request(
            'PATCH',
            '/api/me/magazine-style',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: sprintf('{"magazineStyle":"%s"}', $style),
        );
    }

    private function magazineStyle(KernelBrowser $client): mixed
    {
        $preferences = $this->payload($client)['preferences'];
        self::assertIsArray($preferences);

        return $preferences['magazineStyle'];
    }

    public function testANewAccountIsBoxed(): void
    {
        $client = static::createClient();
        $this->signIn($client, 'boxed-by-default@example.test');

        $client->request('GET', '/api/me');

        self::assertResponseIsSuccessful();
        self::assertSame('boxed', $this->magazineStyle($client));
    }

    public function testChangingTheStylePersistsIt(): void
    {
        $client = static::createClient();
        $this->signIn($client, 'airy-reader@example.test');

        $this->patchStyle($client, 'airy');

        self::assertResponseIsSuccessful();
        self::assertSame('airy', $this->magazineStyle($client));

        $this->entityManager()->clear();
        $reloaded = $this->users()->findOneBy(['email' => 'airy-reader@example.test']);
        self::assertNotNull($reloaded);
        self::assertSame(MagazineStyle::Airy, $reloaded->getPreferences()->getMagazineStyle());
    }

    public function testAnUnknownStyleIsRejected(): void
    {
        $client = static::createClient();
        $this->signIn($client, 'cards-please@example.test');

        $this->patchStyle($client, 'cards');

        self::assertResponseStatusCodeSame(422);
    }

    public function testAnEmptyBodyIsRejected(): void
    {
        $client = static::createClient();
        $this->signIn($client, 'empty-body@example.test');

        $client->request(
            'PATCH',
            '/api/me/magazine-style',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{}',
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testASignedOutClientIsRejected(): void
    {
        $client = static::createClient();

        $this->patchStyle($client, 'airy');

        self::assertResponseStatusCodeSame(401);
    }

    public function testAPreferencesWriteLeavesTheStyleAlone(): void
    {
        $client = static::createClient();
        $this->signIn($client, 'independent-writes@example.test');
        $this->patchStyle($client, 'airy');

        $client->request(
            'PATCH',
            '/api/me/preferences',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"scrapeFallbackEnabled":true}',
        );

        self::assertResponseIsSuccessful();
        self::assertSame('airy', $this->magazineStyle($client));
    }
}
