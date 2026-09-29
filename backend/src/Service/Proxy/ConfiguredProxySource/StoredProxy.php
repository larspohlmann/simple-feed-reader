<?php

declare(strict_types=1);

namespace App\Service\Proxy\ConfiguredProxySource;

use App\Entity\ProxyServerSettings;
use App\Service\Fetch\EgressProxySource\EgressProxySourceInterface;
use App\Service\Fetch\Model\ProxyConfigModel;
use App\Service\Proxy\Crypto\ProxyPasswordCipher;
use App\Service\Proxy\StoredProxySettings\StoredProxySettingsInterface;

final readonly class StoredProxy implements EgressProxySourceInterface, ConfiguredProxySourceInterface
{
    public function __construct(
        private StoredProxySettingsInterface $storedSettings,
        private ProxyPasswordCipher $cipher,
    ) {
    }

    public function configuredProxy(): ?ProxyConfigModel
    {
        return $this->proxyFrom($this->storedSettings->findSingleton());
    }

    public function egressProxy(): ?ProxyConfigModel
    {
        $settings = $this->storedSettings->findSingleton();

        return null !== $settings && $settings->isEnabled() ? $this->proxyFrom($settings) : null;
    }

    private function proxyFrom(?ProxyServerSettings $settings): ?ProxyConfigModel
    {
        if (null === $settings || '' === $settings->getHost()) {
            return null;
        }

        return new ProxyConfigModel(
            $settings->getType(),
            $settings->getHost(),
            $settings->getPort(),
            $settings->getUsername(),
            $settings->hasPassword() ? $this->cipher->open($settings->getSealedPassword()) : null,
            $settings->isDirectFallback(),
            $settings->isRemoteDns(),
        );
    }
}
