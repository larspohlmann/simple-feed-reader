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
 * anyway), which makes suspension effective immediately instead of waiting
 * for the 7-day token to expire. Also where an expired trial takes effect:
 * TrialExpiryGuard flips the account to Suspended here.
 */
final readonly class UserChecker implements UserCheckerInterface
{
    public function __construct(private TrialExpiryGuard $trialExpiryGuard)
    {
    }

    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        $this->trialExpiryGuard->enforce($user);

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
