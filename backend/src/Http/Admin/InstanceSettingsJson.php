<?php

declare(strict_types=1);

namespace App\Http\Admin;

use App\Service\Auth\RegistrationPolicy;
use App\Service\Settings\InstanceSettings;
use App\Service\Settings\PasskeyRelyingParty;

/**
 * The admin settings payload. mailEnabled is read-only here — it reflects the
 * deploy-time MAIL_DISABLED flag, not a toggle the admin can flip — but the UI
 * needs it to explain why the email-confirmation switch is disabled.
 *
 * passkeyRpIdEffective is likewise read-only: it is always what the server
 * would actually use right now — the stored override, or the derived host —
 * so an admin who leaves passkeyRpId empty can still see what they are
 * getting.
 */
final readonly class InstanceSettingsJson
{
    /**
     * @return array{
     *     requireEmailConfirmation: bool,
     *     requireApproval: bool,
     *     mailEnabled: bool,
     *     publicBaseUrl: string|null,
     *     passkeyRpId: string|null,
     *     passkeyRpName: string|null,
     *     passkeyRpIdEffective: string,
     *     passkeySignInEnabled: bool,
     * }
     */
    public static function from(
        RegistrationPolicy $policy,
        InstanceSettings $settings,
        PasskeyRelyingParty $relyingParty,
    ): array {
        return [
            // The stored toggle, not the effective value: the admin sees what
            // they set, and mailEnabled explains any divergence.
            'requireEmailConfirmation' => $policy->storedEmailConfirmationRequired(),
            'requireApproval' => $policy->approvalRequired(),
            'mailEnabled' => $policy->mailEnabled(),
            'publicBaseUrl' => $settings->getPublicBaseUrl(),
            'passkeyRpId' => $settings->getPasskeyRpId(),
            'passkeyRpName' => $settings->getPasskeyRpName(),
            'passkeyRpIdEffective' => $relyingParty->id(),
            'passkeySignInEnabled' => $settings->passkeySignInEnabled(),
        ];
    }
}
