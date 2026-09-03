<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Enum\UserStatus;
use App\Service\Mail\MailCapability;
use App\Service\Settings\InstanceSettings;

/**
 * The single source of truth for what a new registration becomes.
 *
 * Combines the deploy-time mail capability (#230) with the admin's runtime gate
 * toggles (#224); registration, verification, the OAuth linker and the register
 * API response all read from here:
 *
 *  - mail off forces email confirmation off (nothing can deliver the link);
 *  - approval is independent of mail (an admin can still approve by hand).
 */
final readonly class RegistrationPolicy
{
    public function __construct(
        private MailCapability $mail,
        private InstanceSettings $settings,
    ) {
    }

    public function mailEnabled(): bool
    {
        return $this->mail->isEnabled();
    }

    public function emailConfirmationRequired(): bool
    {
        return $this->settings->requireEmailConfirmation() && $this->mailEnabled();
    }

    /**
     * The stored toggle, unmodified by mail capability. Distinct from
     * {@see self::emailConfirmationRequired()}: the admin settings UI must show
     * what the admin set, and let mailEnabled() explain any divergence, rather
     * than silently showing the mail-forced effective value.
     */
    public function storedEmailConfirmationRequired(): bool
    {
        return $this->settings->requireEmailConfirmation();
    }

    public function approvalRequired(): bool
    {
        return $this->settings->requireApproval();
    }

    /**
     * The status any new email/password signup would receive under the current
     * policy. Instance-wide and public — it depends on no address, which is what
     * lets the register endpoint return it without becoming an existence oracle.
     */
    public function prospectiveStatusForEmailSignup(): UserStatus
    {
        if ($this->emailConfirmationRequired()) {
            return UserStatus::PendingVerification;
        }

        if ($this->approvalRequired()) {
            return UserStatus::PendingApproval;
        }

        return UserStatus::Active;
    }
}
