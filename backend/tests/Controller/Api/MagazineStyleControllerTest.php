<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\User;
use App\Service\Reader\MagazineStyle;
use App\Tests\Support\ApiTestCase;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The account's own view of, and write path onto, the magazine's entry design
 * (#723). Tokens come straight from the JWT manager, as in MeControllerTest.
 */
final class MagazineStyleControllerTest extends ApiTestCase
{
    /** Attaches a bearer token to every subsequent request this client makes. */
    private function authenticate(KernelBrowser $client, string $email): void
    {
        $user = $this->users()->findOneByEmail($email);
        self::assertInstanceOf(User::class, $user);

        /** @var JWTTokenManagerInterface $manager */
        $manager = self::getContainer()->get(JWTTokenManagerInterface::class);

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $manager->create($user));
    }

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
        $this->factory()->create('boxed-by-default@example.test');
        $this->authenticate($client, 'boxed-by-default@example.test');

        $client->request('GET', '/api/me');

        self::assertResponseIsSuccessful();
        self::assertSame('boxed', $this->magazineStyle($client));
    }

    public function testChangingTheStylePersistsIt(): void
    {
        $client = static::createClient();
        $this->factory()->create('airy-reader@example.test');
        $this->authenticate($client, 'airy-reader@example.test');

        $this->patchStyle($client, 'airy');

        self::assertResponseIsSuccessful();
        self::assertSame('airy', $this->magazineStyle($client));

        $this->em()->clear();
        $reloaded = $this->users()->findOneBy(['email' => 'airy-reader@example.test']);
        self::assertNotNull($reloaded);
        self::assertSame(MagazineStyle::Airy, $reloaded->getPreferences()->getMagazineStyle());
    }

    public function testAnUnknownStyleIsRejected(): void
    {
        $client = static::createClient();
        $this->factory()->create('cards-please@example.test');
        $this->authenticate($client, 'cards-please@example.test');

        $this->patchStyle($client, 'cards');

        self::assertResponseStatusCodeSame(422);
    }

    public function testAnEmptyBodyIsRejected(): void
    {
        $client = static::createClient();
        $this->factory()->create('empty-body@example.test');
        $this->authenticate($client, 'empty-body@example.test');

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
        $this->factory()->create('independent-writes@example.test');
        $this->authenticate($client, 'independent-writes@example.test');
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
