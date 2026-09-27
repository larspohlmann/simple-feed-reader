<?php

declare(strict_types=1);

namespace App\Service\Mail\Settings;

use App\Entity\MailConnection;
use App\Entity\MailServerSettings;
use App\Repository\MailServerSettingsRepository;
use App\Service\Mail\Settings\Crypto\MailPasswordCipher;
use App\Service\Mail\Settings\Exception\IncompleteMailConfigurationException;
use App\Service\Proxy\ProxySettings;
use Doctrine\ORM\EntityManagerInterface;

final readonly class MailSettings
{
    public function __construct(
        private MailServerSettingsRepository $repository,
        private EntityManagerInterface $em,
        private MailPasswordCipher $cipher,
        private MailFallback $fallback,
        private ProxySettings $proxySettings,
    ) {
    }

    public function overview(): MailSettingsOverview
    {
        $saved = $this->repository->findSingleton();
        $proxy = $this->proxySettings->current();

        return new MailSettingsOverview(
            null === $saved ? null : MailSettingsSnapshot::fromEntity($saved),
            $this->fallback->connection(),
            $proxy->isConfigured() ? $proxy->connection : null,
        );
    }

    public function resetToEnvironment(): void
    {
        $settings = $this->repository->findSingleton();
        if (null !== $settings) {
            $this->em->remove($settings);
            $this->em->flush();
        }
    }

    public function update(MailSettingsUpdate $update): void
    {
        $existing = $this->repository->findSingleton();
        $this->guardAgainstEnablingWithoutATransport($update->connection);
        $this->guardAgainstIncompleteAuthenticatedRow($update, $existing);
        $this->guardAgainstProxyRoutingWithoutAProxy($update->connection);

        $settings = $existing;
        if (null === $settings) {
            $settings = new MailServerSettings();
            $this->em->persist($settings);
        }

        $this->apply($update, $settings);
        $this->em->flush();
    }

    private function guardAgainstIncompleteAuthenticatedRow(
        MailSettingsUpdate $update,
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

    private function apply(MailSettingsUpdate $update, MailServerSettings $settings): void
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
