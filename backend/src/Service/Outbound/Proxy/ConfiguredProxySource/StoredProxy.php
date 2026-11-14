<?php

declare(strict_types=1);

namespace App\Service\Outbound\Proxy\ConfiguredProxySource;

use App\Entity\ProxyServerSettings;
use App\Service\Outbound\Fetch\EgressProxySource\EgressProxySourceInterface;
use App\Service\Outbound\Fetch\Model\ProxyConfigModel;
use App\Service\Outbound\Proxy\Crypto\ProxyPasswordCipher;
use App\Service\Outbound\Proxy\StoredProxySettings\StoredProxySettingsInterface;

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
