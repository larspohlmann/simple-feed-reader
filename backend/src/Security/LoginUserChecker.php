<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Enum\UserStatus;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The login firewalls' checker: status is checked post-auth, after the credential verified. A pre-auth check would
 * tell anyone who guesses an address that it is suspended. Why the api firewall differs: see
 * docs/security.md#account-status-checks
 */
final readonly class LoginUserChecker implements UserCheckerInterface
{
    public function __construct(private TrialExpiryGuard $trialExpiryGuard)
    {
    }

    /**
     * Deliberately empty — see the class docblock. Moving the status check here
     * would reopen the enumeration oracle.
     */
    public function checkPreAuth(UserInterface $user): void
    {
    }

    // $token is unused here but part of the signature: UserCheckerInterface is
    // adding `?TokenInterface $token` to checkPostAuth in its next major, and
    // Symfony's DebugClassLoader deprecates implementations that omit it.
    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if (!$user instanceof User) {
            return;
        }

        $this->trialExpiryGuard->enforce($user);

        if (UserStatus::Active !== $user->getStatus()) {
            throw new AccountStatusException($user->getStatus()->value);
        }
    }
}
