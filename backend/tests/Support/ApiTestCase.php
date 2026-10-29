<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Fixture access shared by controller tests. A suite that proves how a token is issued logs in through the API
 * instead of minting one here.
 */
abstract class ApiTestCase extends WebTestCase
{
    protected function factory(): UserFactory
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        return new UserFactory($entityManager, $hasher);
    }

    protected function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        return $entityManager;
    }

    protected function users(): UserRepository
    {
        /** @var UserRepository $repository */
        $repository = self::getContainer()->get(UserRepository::class);

        return $repository;
    }

    protected function tokenFor(User $user): string
    {
        /** @var JWTTokenManagerInterface $tokens */
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);

        return $tokens->create($user);
    }

    /** @return array{HTTP_AUTHORIZATION: string} */
    protected function bearer(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }

    /** @return array{HTTP_AUTHORIZATION: string} */
    protected function bearerFor(User $user): array
    {
        return $this->bearer($this->tokenFor($user));
    }

    /** @return array{HTTP_AUTHORIZATION: string, CONTENT_TYPE: string} */
    protected function jsonBearerFor(User $user): array
    {
        return [...$this->bearerFor($user), 'CONTENT_TYPE' => 'application/json'];
    }

    /** @return array{0: array{HTTP_AUTHORIZATION: string}, 1: User} */
    protected function bearerAndUser(string $email): array
    {
        $user = $this->factory()->create($email);

        return [$this->bearerFor($user), $user];
    }

    protected function authenticate(KernelBrowser $client, User $user): void
    {
        $client->setServerParameter('HTTP_AUTHORIZATION', $this->bearerFor($user)['HTTP_AUTHORIZATION']);
    }

    protected function signIn(KernelBrowser $client, string $email): User
    {
        $user = $this->factory()->create($email);
        $this->authenticate($client, $user);

        return $user;
    }

    /** Every deliberate API failure answers problem+json — the contract this pins beside the status. */
    protected function assertRejected(KernelBrowser $client, int $status): void
    {
        $response = $client->getResponse();
        self::assertSame($status, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
    }

    /** @return array<string, mixed> */
    protected function payload(KernelBrowser $client): array
    {
        $decoded = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
