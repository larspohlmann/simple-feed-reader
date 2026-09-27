<?php

declare(strict_types=1);

namespace App\Service\Mail\Settings;

use App\Repository\MailServerSettingsRepository;
use App\Service\Mail\MailSendingSettings;
use App\Service\Mail\Settings\Crypto\MailPasswordCipher;

final readonly class EffectiveMailSettings implements MailSendingSettings
{
    public function __construct(
        private MailServerSettingsRepository $repository,
        private MailPasswordCipher $cipher,
        private MailFallback $fallback,
    ) {
    }

    public function isSendingEnabled(): bool
    {
        $settings = $this->repository->findSingleton();

        return null !== $settings ? $settings->isEnabled() : $this->fallback->connection()->enabled;
    }

    public function identity(): MailIdentity
    {
        $settings = $this->repository->findSingleton();

        if (null !== $settings && '' !== $settings->getFromAddress()) {
            return new MailIdentity($settings->getFromAddress(), $settings->getFromName());
        }

        return $this->fallback->identity();
    }

    public function configuredTransport(): ?ResolvedMailTransport
    {
        $settings = $this->repository->findSingleton();

        if (null === $settings || '' === $settings->getHost()) {
            return null;
        }

        return new ResolvedMailTransport(
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
