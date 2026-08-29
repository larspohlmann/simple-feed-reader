<?php

declare(strict_types=1);

namespace App\Service\Settings;

/**
 * The WebAuthn relying party this instance registers and asserts credentials
 * against (#624). `id()` is baked into every stored credential at
 * registration time — see {@see RelyingPartyChange} for why altering it is
 * guarded — while `name()` is cosmetic, shown only by the authenticator's own
 * UI.
 */
interface PasskeyRelyingParty
{
    /** The relying-party id: a registrable domain, with no scheme or port. */
    public function id(): string;

    /** The relying-party display name, shown by the authenticator's UI. */
    public function name(): string;
}
