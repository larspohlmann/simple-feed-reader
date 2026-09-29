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
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $hasher,
    ) {
    }

    /**
     * The password-change stamp is the fixed createdAt, not now, so InvalidatePasswordChangeTokensListener never
     * refuses a token minted in a test; tests of that boundary set the stamp themselves.
     *
     * @param list<string> $roles
     */
    public function create(
        string $email,
        string $password = 'correct-horse-battery',
        UserStatus $status = UserStatus::Active,
        array $roles = [],
        string $locale = 'en',
        ?\DateTimeImmutable $lastLoginAt = null,
        ?\DateTimeImmutable $trialEndsAt = null,
        ?int $maxSubscriptions = null,
    ): User {
        $createdAt = new \DateTimeImmutable('2026-07-01 10:00:00');
        $user = new User($email, $createdAt);
        NewUserStatus::apply($user, $status, $createdAt);
        $user->setRoles($roles);
        $user->setLocale($locale);
        $user->setPasswordHash($this->hasher->hashPassword($user, $password), $createdAt);

        if (null !== $lastLoginAt) {
            $user->setLastLoginAt($lastLoginAt);
        }

        $user->setTrialEndsAt($trialEndsAt);
        $user->setMaxSubscriptions($maxSubscriptions);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
