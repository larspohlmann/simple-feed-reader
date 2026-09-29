<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Enum\UserStatus;
use App\Tests\Support\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class MeTest extends WebTestCase
{
    private function factory(): UserFactory
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        return new UserFactory($entityManager, $hasher);
    }

    private function jwt(): JWTTokenManagerInterface
    {
        /** @var JWTTokenManagerInterface $manager */
        $manager = self::getContainer()->get(JWTTokenManagerInterface::class);

        return $manager;
    }

    public function testAnonymousIs401(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/me');

        self::assertResponseStatusCodeSame(401);
    }

    public function testReturnsTheAuthenticatedUser(): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('me@example.com');
        $token = $this->jwt()->create($user);

        $client->request('GET', '/api/me', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertSame('me@example.com', $payload['email']);
        self::assertSame(['ROLE_USER'], $payload['roles']);
        self::assertSame($user->getId(), $payload['id']);
        self::assertArrayNotHasKey('passwordHash', $payload);
    }

    /**
     * Suspends by raw SQL, behind the ORM's back: a flushed entity would stay in the identity map and pass without
     * any reload, so only SQL proves the firewall re-reads the user instead of trusting the JWT's claims.
     */
    public function testSuspensionTakesEffectOnTheNextRequest(): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('soon-suspended@example.com');
        $token = $this->jwt()->create($user);

        $client->request('GET', '/api/me', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseIsSuccessful();

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $updated = $connection->executeStatement(
            'UPDATE app_user SET status = :status WHERE email = :email',
            ['status' => UserStatus::Suspended->value, 'email' => 'soon-suspended@example.com'],
        );
        self::assertSame(1, $updated, 'The suspension must actually have hit a row.');

        $client->request('GET', '/api/me', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        self::assertResponseStatusCodeSame(401);
    }
}
