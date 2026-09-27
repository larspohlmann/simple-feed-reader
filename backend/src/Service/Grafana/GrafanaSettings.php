<?php

declare(strict_types=1);

namespace App\Service\Grafana;

use App\Dto\Admin\GrafanaSettingsRequest;
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

    public function update(GrafanaSettingsRequest $request): void
    {
        $settings = $this->repository->findSingleton();
        if (null === $settings) {
            $settings = new GrafanaSettingsEntity();
            $this->em->persist($settings);
        }

        $connection = $this->connectionFrom($request);

        if ($request->removeToken) {
            $settings->applyWithoutToken($connection);
            $settings->clearStoredToken();
        } elseif (null === $request->token || '' === $request->token) {
            $settings->applyWithoutToken($connection);
        } else {
            $settings->apply($connection, $this->cipher->seal($request->token), $this->hint($request->token));
        }

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

    private function connectionFrom(GrafanaSettingsRequest $request): GrafanaConnection
    {
        return new GrafanaConnection(
            $this->blankToNull($request->lokiPushUrl),
            $this->blankToNull($request->lokiUsername),
            $this->blankToNull($request->grafanaUrl),
            $this->blankToNull($request->pyroscopePushUrl),
            $request->profilingEnabled,
        );
    }

    private function blankToNull(?string $value): ?string
    {
        return null === $value || '' === $value ? null : $value;
    }

    private function hint(string $token): string
    {
        return substr($token, -4);
    }
}
