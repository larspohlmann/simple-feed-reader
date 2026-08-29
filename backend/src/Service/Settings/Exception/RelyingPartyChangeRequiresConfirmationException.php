<?php

declare(strict_types=1);

namespace App\Service\Settings\Exception;

use App\Exception\ApiException;

/**
 * A relying-party id change was requested while credentials still exist. 409,
 * not 422: the request is well-formed, the instance's current state — the
 * stored passkeys — is what forbids it. The admin sees $invalidatedPasskeyCount
 * and can resend the same request with `invalidateExistingPasskeys: true` to
 * confirm.
 */
final class RelyingPartyChangeRequiresConfirmationException extends ApiException
{
    public function __construct(public readonly int $invalidatedPasskeyCount)
    {
        parent::__construct(
            'relying_party_change_requires_confirmation',
            409,
            'Relying party change requires confirmation',
            \sprintf(
                'Changing the passkey relying party id invalidates %d enrolled passkey(s). '
                . 'Resend the request with invalidateExistingPasskeys set to confirm.',
                $invalidatedPasskeyCount,
            ),
        );
    }
}
