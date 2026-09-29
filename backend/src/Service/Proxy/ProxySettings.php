<?php

declare(strict_types=1);

namespace App\Service\Proxy;

use App\Entity\ProxyServerSettings;
use App\Service\Proxy\Crypto\ProxyPasswordCipher;
use App\Service\Proxy\Model\ProxySettingsSnapshotModel;
use App\Service\Proxy\Model\ProxySettingsUpdateModel;
use App\Service\Proxy\StoredProxySettings\StoredProxySettingsInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ProxySettings
{
    public function __construct(
        private StoredProxySettingsInterface $repository,
        private EntityManagerInterface $entityManager,
        private ProxyPasswordCipher $cipher,
    ) {
    }

    public function current(): ProxySettingsSnapshotModel
    {
        return ProxySettingsSnapshotModel::fromEntity($this->repository->findSingleton() ?? new ProxyServerSettings());
    }

    public function update(ProxySettingsUpdateModel $update): void
    {
        $settings = $this->repository->findSingleton();

        if (null === $settings) {
            $settings = new ProxyServerSettings();
            $this->entityManager->persist($settings);
        }

        $this->apply($update, $settings);
        $this->entityManager->flush();
    }

    private function apply(ProxySettingsUpdateModel $update, ProxyServerSettings $settings): void
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
