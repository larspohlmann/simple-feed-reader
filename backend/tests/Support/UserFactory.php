<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\User;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class UserFactory
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
    ) {
    }

    /** @param list<string> $roles */
    public function create(
        string $email,
        string $password = 'correct-horse-battery',
        UserStatus $status = UserStatus::Active,
        array $roles = [],
    ): User {
        $user = new User($email, new \DateTimeImmutable('2026-07-01 10:00:00'));
        $user->setStatus($status);
        $user->setRoles($roles);
        $user->setPasswordHash($this->hasher->hashPassword($user, $password));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
