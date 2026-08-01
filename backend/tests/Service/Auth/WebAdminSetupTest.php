<?php

declare(strict_types=1);

namespace App\Tests\Service\Auth;

use App\Exception\InvalidSetupSecretException;
use App\Exception\SetupUnavailableException;
use App\Repository\UserRepository;
use App\Service\Auth\BootstrapAdminProvisioner;
use App\Service\Auth\WebAdminSetup;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class WebAdminSetupTest extends DbTestCase
{
    private const string SECRET = 'test-setup-secret-abcdef0123456789';

    private function buildWebAdminSetup(string $configuredSecret): WebAdminSetup
    {
        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $provisioner = self::getContainer()->get(BootstrapAdminProvisioner::class);
        self::assertInstanceOf(BootstrapAdminProvisioner::class, $provisioner);
        $jwt = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $jwt);

        return new WebAdminSetup($users, $provisioner, $jwt, $configuredSecret);
    }

    private function seedAdmin(): void
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        (new UserFactory($this->em, $hasher))->create('existing@example.com', roles: ['ROLE_ADMIN']);
    }

    public function testCreatesAdminAndReturnsAToken(): void
    {
        $token = $this->buildWebAdminSetup(self::SECRET)
            ->createFirstAdmin('root@example.com', 'a-strong-password-123', self::SECRET);

        self::assertNotSame('', $token);
        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        self::assertTrue($users->hasAnyAdmin());
    }

    public function testUnavailableWhenNoSecretConfigured(): void
    {
        $this->expectException(SetupUnavailableException::class);
        $this->buildWebAdminSetup('')->createFirstAdmin('root@example.com', 'a-strong-password-123', self::SECRET);
    }

    public function testUnavailableWhenAnAdminExists(): void
    {
        $this->seedAdmin();

        $this->expectException(SetupUnavailableException::class);
        $this->buildWebAdminSetup(self::SECRET)
            ->createFirstAdmin('root@example.com', 'a-strong-password-123', self::SECRET);
    }

    public function testRejectsAWrongSecret(): void
    {
        $this->expectException(InvalidSetupSecretException::class);
        $this->buildWebAdminSetup(self::SECRET)
            ->createFirstAdmin('root@example.com', 'a-strong-password-123', 'wrong-secret');
    }

    public function testAdminExistsWinsOverWrongSecret(): void
    {
        $this->seedAdmin();

        $this->expectException(SetupUnavailableException::class);
        $this->buildWebAdminSetup(self::SECRET)
            ->createFirstAdmin('root@example.com', 'a-strong-password-123', 'wrong-secret');
    }
}
