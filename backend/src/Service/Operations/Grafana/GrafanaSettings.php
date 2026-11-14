<?php

declare(strict_types=1);

namespace App\Service\Operations\Grafana;

use App\Entity\GrafanaSettings as GrafanaSettingsEntity;
use App\Service\Operations\Grafana\Crypto\GrafanaApiKeyCipher;
use App\Service\Operations\Grafana\Model\GrafanaSettingsOverviewModel;
use App\Service\Operations\Grafana\Model\GrafanaSettingsUpdateModel;
use App\Service\Operations\Grafana\StoredGrafanaSettings\StoredGrafanaSettingsInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class GrafanaSettings
{
    public function __construct(
        private StoredGrafanaSettingsInterface $storedSettings,
        private EntityManagerInterface $entityManager,
        private GrafanaApiKeyCipher $cipher,
        private EffectiveGrafanaSettings $effective,
        private GrafanaEnvDefaults $defaults,
    ) {
    }

    public function overview(): GrafanaSettingsOverviewModel
    {
        return new GrafanaSettingsOverviewModel($this->effective->stored(), $this->defaults);
    }

    public function update(GrafanaSettingsUpdateModel $update): void
    {
        $settings = $this->storedSettings->findSingleton();
        if (null === $settings) {
            $settings = new GrafanaSettingsEntity();
            $this->entityManager->persist($settings);
        }

        $this->apply($update, $settings);
        $this->entityManager->flush();
        $this->effective->forgetStored();
    }

    private function apply(GrafanaSettingsUpdateModel $update, GrafanaSettingsEntity $settings): void
    {
        $replacement = $update->token->replacement();
        if (null !== $replacement) {
            $settings->apply($update->connection, $this->cipher->seal($replacement), $this->hint($replacement));

            return;
        }

        $settings->applyWithoutToken($update->connection);
        if ($update->token->isRemoval()) {
            $settings->clearStoredToken();
        }
    }

    private function hint(string $token): string
    {
        return substr($token, -4);
    }
}
