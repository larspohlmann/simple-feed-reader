<?php

declare(strict_types=1);

namespace App\Service\Proxy\ConfiguredProxySource;

use App\Entity\ProxyServerSettings;
use App\Repository\ProxyServerSettingsRepository;
use App\Service\Fetch\EgressProxySource\EgressProxySourceInterface;
use App\Service\Fetch\ProxyConfig;
use App\Service\Proxy\Crypto\ProxyPasswordCipher;

final readonly class StoredProxy implements EgressProxySourceInterface, ConfiguredProxySourceInterface
{
    public function __construct(
        private ProxyServerSettingsRepository $repository,
        private ProxyPasswordCipher $cipher,
    ) {
    }

    public function configuredProxy(): ?ProxyConfig
    {
        return $this->proxyFrom($this->repository->findSingleton());
    }

    public function egressProxy(): ?ProxyConfig
    {
        $settings = $this->repository->findSingleton();

        return null !== $settings && $settings->isEnabled() ? $this->proxyFrom($settings) : null;
    }

    private function proxyFrom(?ProxyServerSettings $settings): ?ProxyConfig
    {
        if (null === $settings || '' === $settings->getHost()) {
            return null;
        }

        return new ProxyConfig(
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
