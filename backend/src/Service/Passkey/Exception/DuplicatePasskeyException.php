<?php

declare(strict_types=1);

namespace App\Service\Passkey\Exception;

use App\Exception\ApiException;

/**
 * The attested credential id already exists in `user_passkey`, caught at the
 * database's unique constraint rather than reached in the normal path:
 * `PasskeyCredentials::excludeListFor()` already tells an honest
 * authenticator which credentials the account holds, so this only fires on a
 * replayed or forged registration — a well-formed request that must never
 * surface as an unhandled 500.
 *
 * The 409-vs-201 distinction is a narrow existence oracle: an authenticated
 * caller who already controls a credential id — their own, or one guessed —
 * learns whether that exact id is registered to ANY account, since the
 * unique constraint is global, not scoped per user. Accepted rather than
 * fixed: a credential id carries roughly 32 bytes of authenticator-chosen
 * entropy, so the oracle can only confirm an id the caller already holds,
 * never enumerate other accounts' ids by guessing — and the constraint must
 * stay global so `findOneByCredentialId()` can look a credential up with no
 * user in hand, the first question an assertion ceremony asks.
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
