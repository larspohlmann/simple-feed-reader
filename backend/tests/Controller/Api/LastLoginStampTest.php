<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The stamp is asserted through the REAL sign-in paths, never by invoking the
 * listener: a listener called directly proves only that the method body runs,
 * not that the dispatcher ever reaches it.
 */
final class LastLoginStampTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    private function factory(): UserFactory
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        return new UserFactory($em, $hasher);
    }

    private function reload(int $id): User
    {
        /** @var UserRepository $users */
        $users = self::getContainer()->get(UserRepository::class);
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        $user = $users->find($id);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    public function testAPasswordLoginStampsTheAccount(): void
    {
        $user = $this->factory()->create('signs-in@example.com', 'correct-horse-battery');
        self::assertNull($user->getLastLoginAt());
        $id = (int) $user->getId();

        $this->client->request(
            'POST',
            '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'email' => 'signs-in@example.com',
                'password' => 'correct-horse-battery',
            ], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
        self::assertNotNull($this->reload($id)->getLastLoginAt());
    }

    public function testAFailedPasswordLoginLeavesTheAccountUnstamped(): void
    {
        $user = $this->factory()->create('wrong-pass@example.com', 'correct-horse-battery');
        $id = (int) $user->getId();

        $this->client->request(
            'POST',
            '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'email' => 'wrong-pass@example.com',
                'password' => 'not-the-password',
            ], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(401);
        self::assertNull($this->reload($id)->getLastLoginAt());
    }
}
