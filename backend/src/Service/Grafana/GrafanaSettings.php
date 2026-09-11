<?php

declare(strict_types=1);

namespace App\Service\Grafana;

use App\Dto\Admin\GrafanaSettingsRequest;
use App\Entity\GrafanaSettings as GrafanaSettingsEntity;
use App\Http\Admin\GrafanaSettingsJson;
use App\Repository\GrafanaSettingsRepository;
use App\Service\Grafana\Crypto\GrafanaApiKeyCipher;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads and writes the instance-wide Grafana row, defaulting to the env values
 * the installer writes for the local container when no row exists. The push
 * handler resolves its endpoint through here, so "no row", the env fallback and
 * the token sealing all live in one place.
 *
 * `class`, not `readonly`: settings() memoises the resolved row so a Loki
 * flush reading pushUrl/username/token in a row issues one SELECT instead of
 * three (mirrors App\Service\Settings\InstanceSettings). The memo is a plain
 * field — request-scoped under PHP-FPM, never promote it to a shared cache.
 * update() clears it so a read after a write sees the new value. Not marked
 * `final`: SettingsLokiEndpointTest stubs this class.
 */
class GrafanaSettings
{
    private ?GrafanaSettingsEntity $memoisedSettings = null;

    public function __construct(
        private readonly GrafanaSettingsRepository $repository,
        private readonly EntityManagerInterface $em,
        private readonly GrafanaApiKeyCipher $cipher,
        private readonly GrafanaEnvDefaults $defaults,
    ) {
    }

    /** @return array<string, mixed> */
    public function view(): array
    {
        return GrafanaSettingsJson::from($this->settings(), $this->defaults->lokiPushUrl, $this->defaults->grafanaUrl);
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
        $this->memoisedSettings = null;
    }

    public function effectiveLokiPushUrl(): ?string
    {
        $override = $this->settings()->getLokiPushUrlOverride();

        return $override ?? ('' === $this->defaults->lokiPushUrl ? null : $this->defaults->lokiPushUrl);
    }

    public function lokiUsername(): ?string
    {
        return $this->settings()->getLokiUsername();
    }

    public function lokiToken(): ?string
    {
        $settings = $this->settings();

        return $settings->hasToken() ? $this->cipher->open($settings->getSealedToken()) : null;
    }

    /**
     * Never persisted: a fresh GrafanaSettings stands in for the no-row case
     * only for the span of one request. update() above has its own
     * findSingleton()-then-persist path and never reads through this memo.
     */
    private function settings(): GrafanaSettingsEntity
    {
        return $this->memoisedSettings ??= $this->repository->findSingleton() ?? new GrafanaSettingsEntity();
    }

    private function connectionFrom(GrafanaSettingsRequest $request): GrafanaConnection
    {
        return new GrafanaConnection(
            $this->blankToNull($request->lokiPushUrl),
            $this->blankToNull($request->lokiUsername),
            $this->blankToNull($request->grafanaUrl),
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
