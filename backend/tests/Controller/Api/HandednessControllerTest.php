<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Enum\Handedness;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\StoredUserSettings;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

final class HandednessControllerTest extends ApiTestCase
{
    private function patchHandedness(KernelBrowser $client, string $handedness): void
    {
        $client->request(
            'PATCH',
            '/api/me/handedness',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: sprintf('{"handedness":"%s"}', $handedness),
        );
    }

    private function handedness(KernelBrowser $client): mixed
    {
        $preferences = $this->payload($client)['preferences'];
        self::assertIsArray($preferences);

        return $preferences['handedness'];
    }

    public function testANewAccountIsRightHanded(): void
    {
        $client = static::createClient();
        $this->signIn($client, 'right-by-default@example.test');

        $client->request('GET', '/api/me');

        self::assertResponseIsSuccessful();
        self::assertSame('right', $this->handedness($client));
    }

    public function testChangingTheHandednessPersistsIt(): void
    {
        $client = static::createClient();
        $this->signIn($client, 'left-thumb@example.test');

        $this->patchHandedness($client, 'left');

        self::assertResponseIsSuccessful();
        self::assertSame('left', $this->handedness($client));

        $this->entityManager()->clear();
        $reloaded = $this->users()->findOneBy(['email' => 'left-thumb@example.test']);
        self::assertNotNull($reloaded);
        self::assertSame(
            Handedness::Left,
            StoredUserSettings::reading($this->entityManager(), $reloaded)->getHandedness(),
        );
    }

    public function testAnUnknownHandednessIsRejected(): void
    {
        $client = static::createClient();
        $this->signIn($client, 'ambidextrous@example.test');

        $this->patchHandedness($client, 'both');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testAnEmptyBodyIsRejected(): void
    {
        $client = static::createClient();
        $this->signIn($client, 'no-hand@example.test');

        $client->request(
            'PATCH',
            '/api/me/handedness',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{}',
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testASignedOutClientIsRejected(): void
    {
        $client = static::createClient();

        $this->patchHandedness($client, 'left');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testAMagazineStyleWriteLeavesTheHandednessAlone(): void
    {
        $client = static::createClient();
        $this->signIn($client, 'hand-stays@example.test');
        $this->patchHandedness($client, 'left');

        $client->request(
            'PATCH',
            '/api/me/magazine-style',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"magazineStyle":"airy"}',
        );

        self::assertResponseIsSuccessful();
        self::assertSame('left', $this->handedness($client));
    }
}
