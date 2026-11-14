<?php

declare(strict_types=1);

namespace App\Service\Settings\Exception;

/** A relying-party id change while passkeys exist; resending with invalidateExistingPasskeys confirms it. */
final class RelyingPartyChangeRequiresConfirmationException extends \RuntimeException
{
    public function __construct(public readonly int $invalidatedPasskeyCount)
    {
        parent::__construct(\sprintf(
            'Changing the passkey relying party id invalidates %d enrolled passkey(s). '
            . 'Resend the request with invalidateExistingPasskeys set to confirm.',
            $invalidatedPasskeyCount,
        ));
    }
}
