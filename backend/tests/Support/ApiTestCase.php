<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The fixture helpers that were hand-rolled, byte-for-byte, in three separate
 * controller test classes: a `UserFactory` bound to the current kernel's
 * services, the user repository, the entity manager, and a decoded JSON
 * response body.
 *
 * Deliberately narrow. How a test authenticates — minting a token straight
 * from the JWT manager, going through `/api/auth/login`, or something else —
 * varies by what the suite is actually proving, so that stays in the
 * concrete test class rather than growing into a one-size-fits-all method
 * here.
 */
abstract class ApiTestCase extends WebTestCase
{
    protected function factory(): UserFactory
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        return new UserFactory($em, $hasher);
    }

    protected function em(): EntityManagerInterface
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

    /** @return array<string, mixed> */
    protected function payload(KernelBrowser $client): array
    {
        $decoded = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
