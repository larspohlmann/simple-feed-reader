<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Enum\UserStatus;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Runs on every authenticated request (the Doctrine provider reloads the user
 * from the DB anyway), which is what makes suspension effective immediately
 * instead of when the 7-day token expires.
 */
final class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        if (UserStatus::Active !== $user->getStatus()) {
            throw new AccountStatusException($user->getStatus()->value);
        }
    }

    // Empty, but the signature carries $token because UserCheckerInterface is
    // adding `?TokenInterface $token` to checkPostAuth in its next major, and
    // Symfony's DebugClassLoader deprecates implementations that omit it.
    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
    }
}
