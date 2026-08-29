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
 *
 * ACCEPTED TRADE-OFF (#624, fix round 2): the 409-vs-201 distinction is a
 * narrow existence oracle. An authenticated caller who already controls a
 * credential id — their own, from a previous attempt, or one guessed —
 * learns whether that exact id is registered to ANY account, since the
 * unique constraint is global rather than scoped to a user. This is judged
 * acceptable rather than fixed: a credential id carries roughly 32 bytes of
 * authenticator-chosen entropy, so the oracle can only CONFIRM a specific id
 * already held by the caller, never enumerate other accounts' ids by
 * guessing, and there is no clean way to keep the constraint global (needed
 * so `findOneByCredentialId()` can look a credential up with no user in
 * hand, the very first question an assertion ceremony asks) while also
 * hiding its collisions from the account that triggered one. Recorded here
 * so a future reader does not "fix" this into a uniform 400 that would only
 * turn a harmless signal into a confusing one.
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
