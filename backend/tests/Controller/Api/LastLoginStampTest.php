<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Tests\Support\ReloadsEntities;
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
    use ReloadsEntities;

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

    public function testAPasswordLoginStampsTheAccount(): void
    {
        $user = $this->factory()->create('signs-in@example.com', 'correct-horse-battery');
        self::assertNull($user->getLastLoginAt());

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
        self::assertNotNull($this->reload($user)->getLastLoginAt());
    }

    public function testAFailedPasswordLoginLeavesTheAccountUnstamped(): void
    {
        $user = $this->factory()->create('wrong-pass@example.com', 'correct-horse-battery');

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
        self::assertNull($this->reload($user)->getLastLoginAt());
    }
}
