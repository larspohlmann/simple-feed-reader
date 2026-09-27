<?php

declare(strict_types=1);

namespace App\Service\Mail;

/**
 * Whether this instance may send mail at all. A saved admin toggle governs;
 * with no row, enablement derives from the env fallback (a real transport = on).
 */
final readonly class MailCapability
{
    public function __construct(private MailSendingSettings $settings)
    {
    }

    public function isEnabled(): bool
    {
        return $this->settings->isSendingEnabled();
    }
}
