<?php

declare(strict_types=1);

namespace App\Tests\Service\Auth;

use App\Enum\UserStatus;
use App\Service\Auth\BootstrapAdminProvisioner;
use App\Tests\DbTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class BootstrapAdminProvisionerTest extends DbTestCase
{
    private function provisioner(): BootstrapAdminProvisioner
    {
        $service = self::getContainer()->get(BootstrapAdminProvisioner::class);
        self::assertInstanceOf(BootstrapAdminProvisioner::class, $service);

        return $service;
    }

    public function testProvisionsAnActiveAdmin(): void
    {
        $admin = $this->provisioner()->provision('root@example.com', 'a-strong-password-123');

        self::assertSame(UserStatus::Active, $admin->getStatus());
        self::assertContains('ROLE_ADMIN', $admin->getRoles());
        self::assertNotNull($admin->getApprovedAt());

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        self::assertTrue($hasher->isPasswordValid($admin, 'a-strong-password-123'));
    }

    public function testIsIdempotentByEmail(): void
    {
        $first = $this->provisioner()->provision('root@example.com', 'a-strong-password-123');
        $second = $this->provisioner()->provision('root@example.com', 'a-different-password-456');

        self::assertSame($first->getId(), $second->getId());
        self::assertCount(1, $this->em->getRepository($first::class)->findAll());
    }
}
