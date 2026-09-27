<?php

declare(strict_types=1);

namespace App\Service\Proxy;

use App\Entity\ProxyServerSettings;
use App\Repository\ProxyServerSettingsRepository;
use App\Service\Fetch\ProxyConfig;
use App\Service\Proxy\Crypto\ProxyPasswordCipher;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads and writes the instance-wide proxy row, defaulting to "not configured"
 * when no row exists. The rest of the app depends on this, never on the entity
 * or repository directly, so "no row yet" and the sealing both live in one place.
 */
readonly class ProxySettings
{
    public function __construct(
        private ProxyServerSettingsRepository $repository,
        private EntityManagerInterface $em,
        private ProxyPasswordCipher $cipher,
    ) {
    }

    public function current(): ProxySettingsSnapshot
    {
        return ProxySettingsSnapshot::fromEntity($this->repository->findSingleton() ?? new ProxyServerSettings());
    }

    public function update(ProxySettingsUpdate $update): void
    {
        $settings = $this->repository->findSingleton();

        if (null === $settings) {
            $settings = new ProxyServerSettings();
            $this->em->persist($settings);
        }

        $this->apply($update, $settings);
        $this->em->flush();
    }

    /** The stored connection regardless of the enable switch — the tester probes this. */
    public function configuredProxy(): ?ProxyConfig
    {
        return $this->proxyFrom($this->repository->findSingleton());
    }

    /** The connection only when it is turned on — the fetch paths resolve this. */
    public function egressProxy(): ?ProxyConfig
    {
        $settings = $this->repository->findSingleton();

        return null !== $settings && $settings->isEnabled() ? $this->proxyFrom($settings) : null;
    }

    private function apply(ProxySettingsUpdate $update, ProxyServerSettings $settings): void
    {
        $replacement = $update->password->replacement();
        if (null !== $replacement) {
            $settings->apply($update->connection, $this->cipher->seal($replacement));

            return;
        }

        $settings->applyWithoutPassword($update->connection);
        if ($update->password->isRemoval()) {
            $settings->clearStoredPassword();
        }
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
