<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class VersionControllerTest extends WebTestCase
{
    private function bearerToken(): string
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        /** @var JWTTokenManagerInterface $tokens */
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);

        return $tokens->create((new UserFactory($entityManager, $hasher))->create('version@example.com'));
    }

    /**
     * The route carries no access_control rule of its own — it is covered by the
     * `^/api/` catch-all. Asserted through the real firewall, because that is
     * the only thing that proves the catch-all still reaches it.
     */
    public function testAnonymousIs401(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/version');

        self::assertResponseStatusCodeSame(401);
    }

    public function testReportsTheRunningBuild(): void
    {
        $client = self::createClient();
        $token = $this->bearerToken();

        $client->request('GET', '/api/version', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertArrayHasKey('version', $payload);
        self::assertArrayHasKey('commit', $payload);
        self::assertArrayHasKey('builtAt', $payload);
        // The test checkout has no deployed version.json, so this is the
        // development fallback rather than a tag.
        self::assertSame('dev', $payload['version']);
    }
}
