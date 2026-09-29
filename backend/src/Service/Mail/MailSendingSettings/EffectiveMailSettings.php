<?php

declare(strict_types=1);

namespace App\Service\Mail\MailSendingSettings;

use App\Repository\MailServerSettingsRepository;
use App\Service\Mail\Settings\Crypto\MailPasswordCipher;
use App\Service\Mail\Settings\MailFallback;
use App\Service\Mail\Settings\Model\MailIdentityModel;
use App\Service\Mail\Settings\Model\ResolvedMailTransportModel;

final readonly class EffectiveMailSettings implements MailSendingSettingsInterface
{
    public function __construct(
        private MailServerSettingsRepository $mailServerSettings,
        private MailPasswordCipher $cipher,
        private MailFallback $fallback,
    ) {
    }

    public function isSendingEnabled(): bool
    {
        $settings = $this->mailServerSettings->findSingleton();

        return null !== $settings ? $settings->isEnabled() : $this->fallback->connection()->enabled;
    }

    public function identity(): MailIdentityModel
    {
        $settings = $this->mailServerSettings->findSingleton();

        if (null !== $settings && '' !== $settings->getFromAddress()) {
            return new MailIdentityModel($settings->getFromAddress(), $settings->getFromName());
        }

        return $this->fallback->identity();
    }

    public function configuredTransport(): ?ResolvedMailTransportModel
    {
        $settings = $this->mailServerSettings->findSingleton();

        if (null === $settings || '' === $settings->getHost()) {
            return null;
        }

        return new ResolvedMailTransportModel(
            $settings->getHost(),
            $settings->getPort(),
            $settings->getUsername(),
            $settings->hasPassword() ? $this->cipher->open($settings->getSealedPassword()) : null,
            $settings->getEncryption(),
            $settings->usesProxy(),
        );
    }

    public function activeTransportDsnFallback(): string
    {
        return $this->fallback->transportDsn();
    }

    public function hasEnvFallback(): bool
    {
        return $this->fallback->connection()->enabled;
    }
}
