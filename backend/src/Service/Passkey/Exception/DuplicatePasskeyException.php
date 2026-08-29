<?php

declare(strict_types=1);

namespace App\Service\Passkey\Exception;

use App\Exception\ApiException;

/**
 * The attested credential id already exists in `user_passkey`, caught at the
 * database's own unique constraint rather than reached at all in the normal
 * path: `PasskeyCredentials::excludeListFor()` already tells an honest
 * authenticator which credentials this account holds, so this only fires on
 * a replayed or forged registration. Either way it is a well-formed request
 * that must never surface as an unhandled 500 (#624, fix round 1).
 */
final class DuplicatePasskeyException extends ApiException
{
    public function __construct()
    {
        parent::__construct(
            'passkey_already_registered',
            409,
            'Passkey already registered',
            'This passkey is already registered.',
        );
    }
}
