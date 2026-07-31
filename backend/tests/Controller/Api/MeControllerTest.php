<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The account's own view of, and write path onto, its locale. Tokens are
 * minted straight from the JWT manager rather than through POST
 * /api/auth/login, so these cases never touch the login throttler's
 * filesystem pool and cannot be poisoned by it.
 */
final class MeControllerTest extends WebTestCase
{
    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        return $em;
    }

    private function users(): UserRepository
    {
        /** @var UserRepository $repository */
        $repository = self::getContainer()->get(UserRepository::class);

        return $repository;
    }

    private function factory(): UserFactory
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        return new UserFactory($this->entityManager(), $hasher);
    }

    /** Attaches a bearer token to every subsequent request this client makes. */
    private function authenticate(KernelBrowser $client, string $email): void
    {
        $user = $this->users()->findOneByEmail($email);
        self::assertInstanceOf(User::class, $user);

        /** @var JWTTokenManagerInterface $manager */
        $manager = self::getContainer()->get(JWTTokenManagerInterface::class);

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $manager->create($user));
    }

    /** @return array<string, mixed> */
    private function payload(KernelBrowser $client): array
    {
        $decoded = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function testTheProfileCarriesTheAccountLocale(): void
    {
        $client = static::createClient();
        $user = $this->factory()->create('locale-reader@example.test');
        $user->setLocale('de');
        $this->entityManager()->flush();

        $this->authenticate($client, 'locale-reader@example.test');
        $client->request('GET', '/api/me');

        self::assertResponseIsSuccessful();
        self::assertSame('de', $this->payload($client)['locale']);
    }

    public function testChangingTheLocalePersistsIt(): void
    {
        $client = static::createClient();
        $this->factory()->create('switcher@example.test');
        $this->authenticate($client, 'switcher@example.test');

        $client->request(
            'PATCH',
            '/api/me',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"locale":"de"}',
        );

        self::assertResponseIsSuccessful();
        self::assertSame('de', $this->payload($client)['locale']);

        $this->entityManager()->clear();
        $reloaded = $this->users()->findOneBy(['email' => 'switcher@example.test']);
        self::assertNotNull($reloaded);
        self::assertSame('de', $reloaded->getLocale());
    }

    public function testAnUnsupportedLocaleIsRejected(): void
    {
        $client = static::createClient();
        $this->factory()->create('klingon@example.test');
        $this->authenticate($client, 'klingon@example.test');

        $client->request(
            'PATCH',
            '/api/me',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"locale":"tlh"}',
        );

        self::assertResponseStatusCodeSame(422);
        self::assertStringStartsWith(
            'application/problem+json',
            (string) $client->getResponse()->headers->get('Content-Type'),
        );

        $this->entityManager()->clear();
        $unchanged = $this->users()->findOneBy(['email' => 'klingon@example.test']);
        self::assertNotNull($unchanged);
        self::assertSame('en', $unchanged->getLocale());
    }

    public function testChangingTheLocaleRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request(
            'PATCH',
            '/api/me',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"locale":"de"}',
        );

        self::assertResponseStatusCodeSame(401);
    }
}
