<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Service\Mail\Settings\MailSettings;

/**
 * Whether this instance may send mail at all. A saved admin toggle governs;
 * with no row, enablement derives from the env fallback (a real transport = on).
 * MAIL_DISABLED is retired: "no mail" is now the natural state of "nothing
 * configured and no real fallback", plus the admin toggle.
 */
final readonly class MailCapability
{
    public function __construct(private MailSettings $settings)
    {
    }

    public function isEnabled(): bool
    {
        return $this->settings->isSendingEnabled();
    }
}
