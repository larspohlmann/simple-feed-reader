<?php

declare(strict_types=1);

namespace App\Http\Admin;

use App\Service\Auth\RegistrationPolicy;

/**
 * The admin settings payload. mailEnabled is read-only here — it reflects the
 * deploy-time MAIL_DISABLED flag, not a toggle the admin can flip — but the UI
 * needs it to explain why the email-confirmation switch is disabled.
 */
final readonly class InstanceSettingsJson
{
    /**
     * @return array{
     *     requireEmailConfirmation: bool,
     *     requireApproval: bool,
     *     mailEnabled: bool,
     *     publicBaseUrl: string|null,
     * }
     */
    public static function from(RegistrationPolicy $policy, ?string $publicBaseUrl): array
    {
        return [
            // The stored toggle, not the effective value: the admin sees what
            // they set, and mailEnabled explains any divergence.
            'requireEmailConfirmation' => $policy->storedEmailConfirmationRequired(),
            'requireApproval' => $policy->approvalRequired(),
            'mailEnabled' => $policy->mailEnabled(),
            'publicBaseUrl' => $publicBaseUrl,
        ];
    }
}
