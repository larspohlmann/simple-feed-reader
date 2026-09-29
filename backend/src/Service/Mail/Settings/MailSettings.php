<?php

declare(strict_types=1);

namespace App\Service\Mail\Settings;

use App\Entity\MailConnection;
use App\Entity\MailServerSettings;
use App\Repository\MailServerSettingsRepository;
use App\Service\Mail\Settings\Crypto\MailPasswordCipher;
use App\Service\Mail\Settings\Exception\IncompleteMailConfigurationException;
use App\Service\Mail\Settings\Model\MailSettingsOverviewModel;
use App\Service\Mail\Settings\Model\MailSettingsSnapshotModel;
use App\Service\Mail\Settings\Model\MailSettingsUpdateModel;
use App\Service\Proxy\ProxySettings;
use Doctrine\ORM\EntityManagerInterface;

final readonly class MailSettings
{
    public function __construct(
        private MailServerSettingsRepository $mailServerSettings,
        private EntityManagerInterface $entityManager,
        private MailPasswordCipher $cipher,
        private MailFallback $fallback,
        private ProxySettings $proxySettings,
    ) {
    }

    public function overview(): MailSettingsOverviewModel
    {
        $saved = $this->mailServerSettings->findSingleton();
        $proxy = $this->proxySettings->current();

        return new MailSettingsOverviewModel(
            null === $saved ? null : MailSettingsSnapshotModel::fromEntity($saved),
            $this->fallback->connection(),
            $proxy->isConfigured() ? $proxy->connection : null,
        );
    }

    public function resetToEnvironment(): void
    {
        $settings = $this->mailServerSettings->findSingleton();
        if (null !== $settings) {
            $this->entityManager->remove($settings);
            $this->entityManager->flush();
        }
    }

    public function update(MailSettingsUpdateModel $update): void
    {
        $existing = $this->mailServerSettings->findSingleton();
        $this->guardAgainstEnablingWithoutATransport($update->connection);
        $this->guardAgainstIncompleteAuthenticatedRow($update, $existing);
        $this->guardAgainstProxyRoutingWithoutAProxy($update->connection);

        $settings = $existing;
        if (null === $settings) {
            $settings = new MailServerSettings();
            $this->entityManager->persist($settings);
        }

        $this->apply($update, $settings);
        $this->entityManager->flush();
    }

    private function guardAgainstIncompleteAuthenticatedRow(
        MailSettingsUpdateModel $update,
        ?MailServerSettings $existing,
    ): void {
        $password = $update->password;
        $willHavePassword = !$password->isRemoval()
            && (null !== $password->replacement() || ($existing?->hasPassword() ?? false));
        $connection = $update->connection;
        $isAuthenticatedTransport = $connection->enabled
            && '' !== $connection->host
            && null !== $connection->username;

        if ($isAuthenticatedTransport && !$willHavePassword) {
            throw IncompleteMailConfigurationException::passwordMissing();
        }
    }

    private function guardAgainstEnablingWithoutATransport(MailConnection $connection): void
    {
        if ($connection->enabled && '' === $connection->host && !$this->fallback->connection()->enabled) {
            throw IncompleteMailConfigurationException::transportMissing();
        }
    }

    private function guardAgainstProxyRoutingWithoutAProxy(MailConnection $connection): void
    {
        if ($connection->useProxy && !$this->proxySettings->current()->isConfigured()) {
            throw IncompleteMailConfigurationException::proxyMissing();
        }
    }

    private function apply(MailSettingsUpdateModel $update, MailServerSettings $settings): void
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
