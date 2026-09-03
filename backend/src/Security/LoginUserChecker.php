<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Enum\UserStatus;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The login firewall's checker. Identical in effect to {@see UserChecker}, but
 * it runs the status check in checkPostAuth instead of checkPreAuth.
 *
 * That difference is the whole point. UserCheckerListener::preCheckCredentials
 * (priority 256) would run before CheckCredentialsListener verifies the
 * password (priority 0), so a preAuth check would answer "this account is
 * suspended" to anyone who merely guesses the address — an enumeration oracle.
 *
 * checkPostAuth fires from AuthenticationSuccessEvent, only once the password
 * is already verified, so a wrong password falls through to the ordinary
 * "invalid credentials" 401, indistinguishable from an unknown address.
 *
 * The api firewall keeps the preAuth UserChecker: there's no password to
 * verify on a JWT request, and preAuth makes revocation take effect on the
 * very next request.
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
