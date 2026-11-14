<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Enum\UserStatus;
use App\Service\Mail\MailCapability;
use App\Service\Settings\InstanceSettings;

/**
 * What a new registration becomes: the admin's gate toggles, with mail off forcing email confirmation off (nothing
 * could deliver the link). Approval does not depend on mail: an admin approves by hand.
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

    /** The toggle as the admin set it, for the settings UI; emailConfirmationRequired() applies the mail rule. */
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
