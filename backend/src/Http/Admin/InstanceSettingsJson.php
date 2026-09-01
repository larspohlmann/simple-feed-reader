<?php

declare(strict_types=1);

namespace App\Http\Admin;

use App\Service\Auth\RegistrationPolicy;
use App\Service\Settings\InstanceSettings;
use App\Service\Settings\PasskeyRelyingParty;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

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
    public function __construct(
        private RegistrationPolicy $policy,
        private InstanceSettings $settings,
        private PasskeyRelyingParty $relyingParty,
        #[Autowire('%env(APP_FRONTEND_URL)%')]
        private string $publicBaseUrlDefault,
    ) {
    }

    /**
     * @return array{
     *     requireEmailConfirmation: bool,
     *     requireApproval: bool,
     *     mailEnabled: bool,
     *     publicBaseUrl: string|null,
     *     publicBaseUrlDefault: string,
     *     passkeyRpId: string|null,
     *     passkeyRpName: string|null,
     *     passkeyRpIdEffective: string,
     *     passkeySignInEnabled: bool,
     * }
     */
    public function current(): array
    {
        return [
            // The stored toggle, not the effective value: the admin sees what
            // they set, and mailEnabled explains any divergence.
            'requireEmailConfirmation' => $this->policy->storedEmailConfirmationRequired(),
            'requireApproval' => $this->policy->approvalRequired(),
            'mailEnabled' => $this->policy->mailEnabled(),
            'publicBaseUrl' => $this->settings->getPublicBaseUrl(),
            'publicBaseUrlDefault' => $this->publicBaseUrlDefault,
            'passkeyRpId' => $this->settings->getPasskeyRpId(),
            'passkeyRpName' => $this->settings->getPasskeyRpName(),
            'passkeyRpIdEffective' => $this->relyingParty->id(),
            'passkeySignInEnabled' => $this->settings->passkeySignInEnabled(),
        ];
    }
}
