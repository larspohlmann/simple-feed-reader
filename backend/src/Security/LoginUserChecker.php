<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Enum\UserStatus;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The login firewall's checker. Identical in effect to {@see UserChecker}, but
 * it runs the status check in checkPostAuth instead of checkPreAuth.
 *
 * That difference is the whole point. UserCheckerListener::preCheckCredentials
 * runs on CheckPassportEvent at priority 256, while CheckCredentialsListener
 * verifies the password at priority 0 — so a preAuth check answers "this
 * account is suspended" to anyone who merely guesses the address, no password
 * needed. That is an account-enumeration oracle.
 *
 * checkPostAuth is invoked from AuthenticationSuccessEvent, which is only
 * dispatched once the password has already been verified. A wrong password
 * therefore never reaches this check and falls through to the ordinary
 * "invalid credentials" 401, indistinguishable from an unknown address.
 *
 * The api firewall deliberately keeps the preAuth UserChecker: there is no
 * password to verify on a JWT request, and preAuth is what makes revocation
 * take effect on the very next request.
 */
final class LoginUserChecker implements UserCheckerInterface
{
    /**
     * Deliberately empty — see the class docblock. Moving the status check here
     * would reopen the enumeration oracle.
     */
    public function checkPreAuth(UserInterface $user): void
    {
    }

    public function checkPostAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        if (UserStatus::Active !== $user->getStatus()) {
            throw new AccountStatusException($user->getStatus()->value);
        }
    }
}
