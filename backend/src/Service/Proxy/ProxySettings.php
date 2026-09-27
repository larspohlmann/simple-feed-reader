<?php

declare(strict_types=1);

namespace App\Service\Proxy;

use App\Entity\ProxyServerSettings;
use App\Repository\ProxyServerSettingsRepository;
use App\Service\Proxy\Crypto\ProxyPasswordCipher;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ProxySettings
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
}
