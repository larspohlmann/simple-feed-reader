<?php

declare(strict_types=1);

namespace App\Service\Passkey\Exception;

use App\Exception\ApiException;

/**
 * Refused by PasskeyRemovalPolicy: the credential being removed is the
 * account's last passkey, and the account has neither a password hash nor a
 * linked OAuth identity to fall back on. Letting this removal through would
 * leave the account with zero sign-in methods — the one outcome this
 * feature must never produce — so the detail names what to add first rather
 * than just saying no.
 */
final class LastSignInMethodException extends ApiException
{
    public function __construct()
    {
        parent::__construct(
            'passkey_last_sign_in_method',
            409,
            'Cannot remove your last sign-in method',
            'This is your only way to sign in. Set a password or link a sign-in provider first.',
        );
    }
}
