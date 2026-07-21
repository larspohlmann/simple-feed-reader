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

    /**
     * $passwordChangedAt defaults to the fixed createdAt rather than to "now".
     * Tokens minted during a test therefore always carry an `iat` well after
     * it, so App\Security\PasswordChangeTokenInvalidator stays out of the way
     * of fixtures that are not about password changes. Tests that DO exercise
     * the boundary set the stamp explicitly.
     *
     * @param list<string> $roles
     */
    public function create(
        string $email,
        string $password = 'correct-horse-battery',
        UserStatus $status = UserStatus::Active,
        array $roles = [],
    ): User {
        $createdAt = new \DateTimeImmutable('2026-07-01 10:00:00');
        $user = new User($email, $createdAt);
        $user->setStatus($status);
        $user->setRoles($roles);
        $user->setPasswordHash($this->hasher->hashPassword($user, $password), $createdAt);

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
