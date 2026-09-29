<?php

declare(strict_types=1);

namespace App\Service\Grafana;

use App\Entity\GrafanaSettings as GrafanaSettingsEntity;
use App\Service\Grafana\Crypto\GrafanaApiKeyCipher;
use App\Service\Grafana\Model\GrafanaSettingsOverviewModel;
use App\Service\Grafana\Model\GrafanaSettingsUpdateModel;
use App\Service\Grafana\StoredGrafanaSettings\StoredGrafanaSettingsInterface;
use App\Service\Profiling\ProfileSampler\ProfileSamplerInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class GrafanaSettings
{
    public function __construct(
        private StoredGrafanaSettingsInterface $repository,
        private EntityManagerInterface $em,
        private GrafanaApiKeyCipher $cipher,
        private EffectiveGrafanaSettings $effective,
        private GrafanaEnvDefaults $defaults,
        private ProfileSamplerInterface $sampler,
    ) {
    }

    public function overview(): GrafanaSettingsOverviewModel
    {
        return new GrafanaSettingsOverviewModel(
            $this->effective->stored(),
            $this->defaults,
            $this->sampler->isAvailable(),
        );
    }

    public function update(GrafanaSettingsUpdateModel $update): void
    {
        $settings = $this->repository->findSingleton();
        if (null === $settings) {
            $settings = new GrafanaSettingsEntity();
            $this->em->persist($settings);
        }

        $this->apply($update, $settings);
        $this->em->flush();
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
