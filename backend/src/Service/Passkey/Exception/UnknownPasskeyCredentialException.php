<?php

declare(strict_types=1);

namespace App\Service\Passkey\Exception;

use App\Exception\ApiException;

/**
 * The assertion named a credential id no account holds (#727). Kept apart
 * from AssertionRejectedException because the browser can only prune the
 * dead entry (Signal API) if the client learns this exact case; a caller who
 * already holds the id learns only whether this instance knows it, the same
 * oracle DuplicatePasskeyException accepts.
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
