<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\User;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Creates the first administrator, or promotes an existing account into that
 * role. The bootstrap admin skips email verification and the approval queue: it
 * is Active with ROLE_ADMIN and approvedAt stamped. Both bootstrap paths — the
 * app:admin:create command and the web setup endpoint — funnel through here, so
 * the provisioning rule lives in exactly one place.
 *
 * Find-or-create by email makes a re-run idempotent rather than duplicating.
 * This service does NOT decide whether provisioning is allowed; the hasAnyAdmin
 * invariant is enforced by each caller (the command refuses, the endpoint 404s).
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
        $admin->setStatus(UserStatus::Active);
        $admin->setApprovedAt($now);
        $admin->setPasswordHash($this->hasher->hashPassword($admin, $password), $now);

        $this->entityManager->persist($admin);
        $this->entityManager->flush();

        return $admin;
    }
}
