<?php

declare(strict_types=1);

namespace App\Service\Mail\MailSendingSettings;

use App\Service\Crypto\Exception\SecretUnreadableException;
use App\Service\Mail\Settings\Model\MailIdentityModel;
use App\Service\Mail\Settings\Model\ResolvedMailTransportModel;

interface MailSendingSettingsInterface
{
    public function isSendingEnabled(): bool;

    public function identity(): MailIdentityModel;

    /**
     * The saved SMTP transport whether or not sending is enabled; null when no host is saved.
     *
     * @throws SecretUnreadableException when its stored password cannot be opened
     */
    public function configuredTransport(): ?ResolvedMailTransportModel;

    public function activeTransportDsnFallback(): string;

    public function hasEnvFallback(): bool;
}
