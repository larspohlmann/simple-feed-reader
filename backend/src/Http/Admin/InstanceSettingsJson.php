<?php

declare(strict_types=1);

namespace App\Http\Admin;

use App\Service\Auth\RegistrationPolicy;
use App\Service\Settings\InstanceSettings;
use App\Service\Settings\PasskeyRelyingParty\PasskeyRelyingPartyInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The admin settings payload. requireEmailConfirmation is the stored toggle, not the effective value; the UI
 * uses mailEnabled to explain why it disables that switch.
 */
final readonly class InstanceSettingsJson
{
    public function __construct(
        private RegistrationPolicy $policy,
        private InstanceSettings $settings,
        private PasskeyRelyingPartyInterface $relyingParty,
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
