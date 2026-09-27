<?php

declare(strict_types=1);

namespace App\Service\Grafana;

use App\Entity\GrafanaSettings as GrafanaSettingsEntity;
use App\Repository\GrafanaSettingsRepository;
use App\Service\Grafana\Crypto\GrafanaApiKeyCipher;
use App\Service\Profiling\ProfileSampler;
use Doctrine\ORM\EntityManagerInterface;

final readonly class GrafanaSettings
{
    public function __construct(
        private GrafanaSettingsRepository $repository,
        private EntityManagerInterface $em,
        private GrafanaApiKeyCipher $cipher,
        private EffectiveGrafanaSettings $effective,
        private GrafanaEnvDefaults $defaults,
        private ProfileSampler $sampler,
    ) {
    }

    public function overview(): GrafanaSettingsOverview
    {
        return new GrafanaSettingsOverview($this->effective->stored(), $this->defaults, $this->sampler->isAvailable());
    }

    public function update(GrafanaSettingsUpdate $update): void
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

    private function apply(GrafanaSettingsUpdate $update, GrafanaSettingsEntity $settings): void
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
