<?php

declare(strict_types=1);

namespace App\Service\Settings;

/**
 * The full instance-setting row, replacing InstanceSettings::update()'s
 * previous three scalar parameters (#624). A value object rather than a wider
 * parameter list: CLAUDE.md caps a method at three parameters, and this row
 * grew a fourth and fifth field (passkeyRpId, passkeyRpName) the moment the
 * relying party became admin-configurable.
 */
final readonly class InstanceSettingsUpdate
{
    public function __construct(
        public bool $requireEmailConfirmation,
        public bool $requireApproval,
        public ?string $publicBaseUrl,
        public ?string $passkeyRpId,
        public ?string $passkeyRpName,
        // Defaulted, unlike the fields above, purely so the many pre-#624
        // call sites that never mention passkeys at all can keep using
        // positional/partial construction. `false` (#624 follow-up,
        // addendum) matches InstanceSetting::$passkeySignInEnabled's own
        // default — see that property's docblock for the full list of five
        // places this has to agree. A test that wants a WORKING passkey
        // configuration (PinsPasskeyRelyingParty, for one) must now pass
        // `passkeySignInEnabled: true` explicitly; it can no longer ride
        // this default the way it could when the default was `true`.
        public bool $passkeySignInEnabled = false,
    ) {
    }
}
