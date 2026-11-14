<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Creates or promotes the first administrator (Active, ROLE_ADMIN, approved, no verification or queue) for both
 * app:admin:create and the web setup; find-or-create keeps a re-run idempotent. Each caller enforces hasAnyAdmin.
 */
final readonly class BootstrapAdminProvisioner
{
    public function __construct(
        private UserRepository $users,
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $hasher,
        private ClockInterface $clock,
    ) {
    }

    public function provision(string $email, string $password): User
    {
        $now = $this->clock->now();
        $admin = $this->users->findOneByEmail($email) ?? new User($email, $now);

        $admin->setRoles(['ROLE_ADMIN']);
        $admin->approve($now);
        $admin->setPasswordHash($this->hasher->hashPassword($admin, $password), $now);

        $this->entityManager->persist($admin);
        $this->entityManager->flush();

        return $admin;
    }
}
