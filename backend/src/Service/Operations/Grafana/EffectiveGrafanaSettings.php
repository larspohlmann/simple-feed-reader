<?php

declare(strict_types=1);

namespace App\Service\Grafana;

use App\Entity\GrafanaSettings as GrafanaSettingsEntity;
use App\Service\Grafana\Crypto\GrafanaApiKeyCipher;
use App\Service\Grafana\Model\GrafanaSettingsSnapshotModel;
use App\Service\Grafana\StoredGrafanaSettings\StoredGrafanaSettingsInterface;
use Symfony\Contracts\Service\ResetInterface;

final class EffectiveGrafanaSettings implements ResetInterface
{
    private ?GrafanaSettingsSnapshotModel $memoised = null;

    public function __construct(
        private readonly StoredGrafanaSettingsInterface $storedSettings,
        private readonly GrafanaApiKeyCipher $cipher,
        private readonly GrafanaEnvDefaults $defaults,
        private readonly GrafanaSettingsCache $cache,
    ) {
    }

    public function stored(): GrafanaSettingsSnapshotModel
    {
        return $this->memoised ??= $this->cache->remember($this->loadSingleton(...));
    }

    public function reset(): void
    {
        $this->memoised = null;
    }

    public function forgetStored(): void
    {
        $this->cache->forget();
        $this->reset();
    }

    public function effectiveLokiPushUrl(): ?string
    {
        return $this->stored()->connection->lokiPushUrl ?? $this->defaultOrNull($this->defaults->lokiPushUrl);
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

    private function loadSingleton(): GrafanaSettingsSnapshotModel
    {
        return GrafanaSettingsSnapshotModel::fromEntity(
            $this->storedSettings->findSingleton() ?? new GrafanaSettingsEntity(),
        );
    }

    private function defaultOrNull(string $default): ?string
    {
        return '' === $default ? null : $default;
    }
}
