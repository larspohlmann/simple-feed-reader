<?php

declare(strict_types=1);

namespace App\Service\Auth\Factory;

use App\Entity\User;
use App\Enum\SupportedLocale;
use App\Enum\UserStatus;
use App\Service\Auth\RegistrationPolicy;
use Psr\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** An account from the registration form, in RegistrationPolicy::prospectiveStatusForEmailSignup()'s status. */
final readonly class SignupUserFactory
{
    public function __construct(
        private UserPasswordHasherInterface $hasher,
        private ClockInterface $clock,
        private RegistrationPolicy $policy,
    ) {
    }

    public function create(string $email, string $plainPassword, string $locale): User
    {
        $now = $this->clock->now();
        $user = new User($email, $now);
        $user->setLocale(\in_array($locale, SupportedLocale::ALL, true) ? $locale : SupportedLocale::ENGLISH);
        $user->setPasswordHash($this->hasher->hashPassword($user, $plainPassword), $now);
        $this->enterSignupStatus($user, $now);

        return $user;
    }

    private function enterSignupStatus(User $user, \DateTimeImmutable $now): void
    {
        match ($this->policy->prospectiveStatusForEmailSignup()) {
            UserStatus::Active => $user->approve($now),
            UserStatus::PendingApproval => $user->queueForApproval(),
            default => null,
        };
    }
}
