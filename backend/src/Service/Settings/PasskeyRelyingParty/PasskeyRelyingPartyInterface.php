<?php

declare(strict_types=1);

namespace App\Service\Settings\PasskeyRelyingParty;

use App\Service\Settings\RelyingPartyChangeGuard;

/** id() is baked into each stored credential, so {@see RelyingPartyChangeGuard} guards a change; name() is cosmetic. */
interface PasskeyRelyingPartyInterface
{
    /** The relying-party id: a registrable domain, with no scheme or port. */
    public function id(): string;

    /** The relying-party display name, shown by the authenticator's UI. */
    public function name(): string;
}
