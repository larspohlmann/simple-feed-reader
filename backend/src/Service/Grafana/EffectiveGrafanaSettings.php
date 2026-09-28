<?php

declare(strict_types=1);

namespace App\Service\Grafana;

use App\Entity\GrafanaSettings as GrafanaSettingsEntity;
use App\Repository\GrafanaSettingsRepository;
use App\Service\Grafana\Crypto\GrafanaApiKeyCipher;
use App\Service\Profiling\ProfilingConfigSource\ProfilingConfigSourceInterface;

final class EffectiveGrafanaSettings implements ProfilingConfigSourceInterface
{
    private ?GrafanaSettingsSnapshot $memoised = null;

    public function __construct(
        private readonly GrafanaSettingsRepository $repository,
        private readonly GrafanaApiKeyCipher $cipher,
        private readonly GrafanaEnvDefaults $defaults,
        private readonly GrafanaSettingsCache $cache,
    ) {
    }

    public function stored(): GrafanaSettingsSnapshot
    {
        return $this->memoised ??= $this->cache->remember($this->loadSingleton(...));
    }

    public function refresh(): void
    {
        $this->memoised = null;
    }

    public function forgetStored(): void
    {
        $this->cache->forget();
        $this->refresh();
    }

    public function effectiveLokiPushUrl(): ?string
    {
        return $this->stored()->connection->lokiPushUrl ?? $this->defaultOrNull($this->defaults->lokiPushUrl);
    }

    public function effectivePyroscopePushUrl(): ?string
    {
        return $this->stored()->connection->pyroscopePushUrl
            ?? $this->defaultOrNull($this->defaults->pyroscopePushUrl);
    }

    public function profilingEnabled(): bool
    {
        return $this->stored()->connection->profilingEnabled;
    }

    public function lokiUsername(): ?string
    {
        return $this->stored()->connection->lokiUsername;
    }

    public function lokiToken(): ?string
    {
        $stored = $this->stored();

        return $stored->hasToken() ? $this->cipher->open($stored->sealedToken) : null;
    }

    private function loadSingleton(): GrafanaSettingsSnapshot
    {
        return GrafanaSettingsSnapshot::fromEntity($this->repository->findSingleton() ?? new GrafanaSettingsEntity());
    }

    private function defaultOrNull(string $default): ?string
    {
        return '' === $default ? null : $default;
    }
}
