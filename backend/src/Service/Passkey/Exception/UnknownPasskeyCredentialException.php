<?php

declare(strict_types=1);

namespace App\Service\Passkey\Exception;

use App\Exception\ApiException;

/**
 * The assertion named a credential id no account holds — its own type, not
 * AssertionRejectedException, so the client can prune the dead browser entry
 * (#727). The oracle it accepts is the one DuplicatePasskeyException accepts.
 */
final class UnknownPasskeyCredentialException extends ApiException
{
    public function __construct()
    {
        parent::__construct(
            'unknown_passkey_credential',
            401,
            'Unknown passkey',
            'This passkey is not registered here.',
        );
    }
}
