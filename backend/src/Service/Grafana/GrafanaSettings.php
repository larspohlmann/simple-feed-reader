<?php

declare(strict_types=1);

namespace App\Service\Grafana;

use App\Dto\Admin\GrafanaSettingsRequest;
use App\Entity\GrafanaSettings as GrafanaSettingsEntity;
use App\Http\Admin\GrafanaSettingsJson;
use App\Repository\GrafanaSettingsRepository;
use App\Service\Grafana\Crypto\GrafanaApiKeyCipher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reads and writes the instance-wide Grafana row, defaulting to the env values
 * the installer writes for the local container when no row exists. The push
 * handler resolves its endpoint through here, so "no row", the env fallback and
 * the token sealing all live in one place.
 */
readonly class GrafanaSettings
{
    public function __construct(
        private GrafanaSettingsRepository $repository,
        private EntityManagerInterface $em,
        private GrafanaApiKeyCipher $cipher,
        #[Autowire('%env(GRAFANA_LOKI_PUSH_URL)%')]
        private string $lokiPushUrlDefault,
        #[Autowire('%env(GRAFANA_URL)%')]
        private string $grafanaUrlDefault,
    ) {
    }

    /** @return array<string, mixed> */
    public function view(): array
    {
        return GrafanaSettingsJson::from(
            $this->repository->findSingleton(),
            $this->lokiPushUrlDefault,
            $this->grafanaUrlDefault,
        );
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
    }

    public function effectiveLokiPushUrl(): ?string
    {
        $override = $this->repository->findSingleton()?->getLokiPushUrlOverride();

        return $override ?? ('' === $this->lokiPushUrlDefault ? null : $this->lokiPushUrlDefault);
    }

    public function lokiUsername(): ?string
    {
        return $this->repository->findSingleton()?->getLokiUsername();
    }

    public function lokiToken(): ?string
    {
        $settings = $this->repository->findSingleton();

        return null !== $settings && $settings->hasToken() ? $this->cipher->open($settings->getSealedToken()) : null;
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
