<?php

declare(strict_types=1);

namespace App\Service\Grafana;

use App\Entity\GrafanaSettings as GrafanaSettingsEntity;
use App\Repository\GrafanaSettingsRepository;
use App\Service\Grafana\Crypto\GrafanaApiKeyCipher;
use App\Service\Profiling\ProfileSampler;
use Doctrine\ORM\EntityManagerInterface;

class GrafanaSettings
{
    private ?GrafanaSettingsSnapshot $memoisedSettings = null;

    public function __construct(
        private readonly GrafanaSettingsRepository $repository,
        private readonly EntityManagerInterface $em,
        private readonly GrafanaApiKeyCipher $cipher,
        private readonly GrafanaEnvDefaults $defaults,
        private readonly ProfileSampler $sampler,
        private readonly GrafanaSettingsCache $cache,
    ) {
    }

    public function overview(): GrafanaSettingsOverview
    {
        return new GrafanaSettingsOverview($this->settings(), $this->defaults, $this->sampler->isAvailable());
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
        $this->cache->forget();
        $this->refresh();
    }

    public function refresh(): void
    {
        $this->memoisedSettings = null;
    }

    public function effectiveLokiPushUrl(): ?string
    {
        return $this->settings()->connection->lokiPushUrl ?? $this->defaultOrNull($this->defaults->lokiPushUrl);
    }

    public function effectivePyroscopePushUrl(): ?string
    {
        return $this->settings()->connection->pyroscopePushUrl
            ?? $this->defaultOrNull($this->defaults->pyroscopePushUrl);
    }

    public function profilingEnabled(): bool
    {
        return $this->settings()->connection->profilingEnabled;
    }

    public function lokiUsername(): ?string
    {
        return $this->settings()->connection->lokiUsername;
    }

    public function lokiToken(): ?string
    {
        $settings = $this->settings();

        return $settings->hasToken() ? $this->cipher->open($settings->sealedToken) : null;
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

    private function settings(): GrafanaSettingsSnapshot
    {
        return $this->memoisedSettings ??= $this->cache->remember($this->loadSingleton(...));
    }

    private function loadSingleton(): GrafanaSettingsSnapshot
    {
        return GrafanaSettingsSnapshot::fromEntity($this->repository->findSingleton() ?? new GrafanaSettingsEntity());
    }

    private function defaultOrNull(string $default): ?string
    {
        return '' === $default ? null : $default;
    }

    private function hint(string $token): string
    {
        return substr($token, -4);
    }
}
