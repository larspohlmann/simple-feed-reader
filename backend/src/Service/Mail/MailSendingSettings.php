<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Service\Crypto\Exception\SecretUnreadableException;
use App\Service\Mail\Settings\MailIdentity;
use App\Service\Mail\Settings\ResolvedMailTransport;

interface MailSendingSettings
{
    public function isSendingEnabled(): bool;

    public function identity(): MailIdentity;

    /**
     * The saved SMTP transport whether or not sending is enabled; null when none is saved.
     *
     * @throws SecretUnreadableException when its stored password cannot be opened
     */
    public function configuredTransport(): ?ResolvedMailTransport;

    public function activeTransportDsnFallback(): string;

    public function hasEnvFallback(): bool;
}
