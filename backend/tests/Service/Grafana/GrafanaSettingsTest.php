<?php

declare(strict_types=1);

namespace App\Tests\Service\Grafana;

use App\Entity\GrafanaSettings as GrafanaSettingsEntity;
use App\Http\Admin\GrafanaSettingsJson;
use App\Service\Grafana\EffectiveGrafanaSettings;
use App\Service\Grafana\GrafanaEnvDefaults;
use App\Service\Grafana\GrafanaSettings;
use App\Service\Grafana\GrafanaSettingsCache;
use App\Service\Grafana\StoredGrafanaSettings\StoredGrafanaSettingsInterface;
use App\Tests\Support\BuildsEffectiveGrafanaSettings;
use App\Tests\Support\GrafanaApiKeyCiphers;
use App\Tests\Support\SettingsRequests;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class GrafanaSettingsTest extends TestCase
{
    use BuildsEffectiveGrafanaSettings;

    private ?GrafanaSettingsEntity $stored = null;

    /**
     * @return array{
     *     lokiPushUrl: string|null, lokiPushUrlDefault: string, lokiPushUrlEffective: string|null,
     *     lokiUsername: string|null, grafanaUrl: string|null, grafanaUrlDefault: string,
     *     grafanaUrlEffective: string|null, hasToken: bool, tokenHint: string, containerPresent: bool,
     * }
     */
    private function viewOf(GrafanaSettings $settings): array
    {
        return GrafanaSettingsJson::from($settings->overview());
    }

    public function testASaveIsVisibleToTheNextViewAndTheTokenStaysHidden(): void
    {
        $settings = $this->settings($this->effective());
        self::assertFalse($this->viewOf($settings)['hasToken']);

        $settings->update(SettingsRequests::grafana(
            lokiPushUrl: 'https://cloud.example/loki/push',
            lokiUsername: 'tenant42',
            grafanaUrl: 'https://cloud.example/grafana',
            token: 'glc_secrettoken',
        )->toUpdate());

        $view = $this->viewOf($settings);
        self::assertSame('https://cloud.example/loki/push', $view['lokiPushUrl']);
        self::assertSame('tenant42', $view['lokiUsername']);
        self::assertSame('https://cloud.example/grafana', $view['grafanaUrl']);
        self::assertTrue($view['hasToken']);
        self::assertSame('oken', $view['tokenHint']);
        self::assertArrayNotHasKey('token', $view);
    }

    public function testANullTokenKeepsTheStoredSecret(): void
    {
        $effective = $this->effective();
        $settings = $this->settings($effective);
        $settings->update(SettingsRequests::grafana(grafanaUrl: 'https://a.example', token: 'glc_first')->toUpdate());

        $settings->update(SettingsRequests::grafana(grafanaUrl: 'https://b.example', token: null)->toUpdate());

        $view = $this->viewOf($settings);
        self::assertTrue($view['hasToken']);
        self::assertSame('https://b.example', $view['grafanaUrl']);
        self::assertSame('glc_first', $effective->lokiToken());
    }

    public function testRemoveTokenClearsTheStoredSecretButKeepsTheConnection(): void
    {
        $effective = $this->effective();
        $settings = $this->settings($effective);
        $settings->update(SettingsRequests::grafana(grafanaUrl: 'https://a.example', token: 'glc_first')->toUpdate());

        $settings->update(
            SettingsRequests::grafana(grafanaUrl: 'https://other.example', removeToken: true)->toUpdate(),
        );

        $view = $this->viewOf($settings);
        self::assertFalse($view['hasToken']);
        self::assertSame('https://other.example', $view['grafanaUrl']);
        self::assertNull($effective->lokiToken());
    }

    public function testRemoveTokenWinsEvenWhenATokenIsAlsoSent(): void
    {
        $effective = $this->effective();
        $settings = $this->settings($effective);
        $settings->update(SettingsRequests::grafana(token: 'glc_first')->toUpdate());

        $settings->update(SettingsRequests::grafana(token: 'glc_second', removeToken: true)->toUpdate());

        self::assertFalse($this->viewOf($settings)['hasToken']);
        self::assertNull($effective->lokiToken());
    }

    public function testBlankUsernameClearsTheStoredOverrideAndItStartsNull(): void
    {
        $effective = $this->effective();
        $settings = $this->settings($effective);
        self::assertNull($effective->lokiUsername());

        $settings->update(SettingsRequests::grafana(lokiUsername: 'tenant42')->toUpdate());
        $settings->update(SettingsRequests::grafana(lokiUsername: '')->toUpdate());

        self::assertNull($effective->lokiUsername());
    }

    /**
     * The admin form saves in php-fpm; the worker re-reads the row in its own process. A save forgets the shared
     * pool, so the worker reads the new row once its memo is reset, not the stale cache.
     */
    public function testAnAdminSaveInvalidatesTheSharedCacheSoTheWorkerSeesTheChange(): void
    {
        $cache = new GrafanaSettingsCache(new ArrayAdapter());
        $webProcess = $this->settings($this->effective($cache));
        $workerProcess = $this->effective($cache);
        self::assertNull($workerProcess->lokiUsername());

        $webProcess->update(SettingsRequests::grafana(lokiUsername: 'tenant42')->toUpdate());

        $workerProcess->reset();
        self::assertSame('tenant42', $workerProcess->lokiUsername());
    }

    public function testUpdateFlushesTheEntityManager(): void
    {
        $repository = $this->createStub(StoredGrafanaSettingsInterface::class);
        $repository->method('findSingleton')->willReturn(new GrafanaSettingsEntity());
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');
        $settings = new GrafanaSettings(
            $repository,
            $entityManager,
            GrafanaApiKeyCiphers::withTestSecret(),
            $this->effective(),
            new GrafanaEnvDefaults('', ''),
        );

        $settings->update(SettingsRequests::grafana(grafanaUrl: 'https://a.example')->toUpdate());
    }

    private function effective(?GrafanaSettingsCache $cache = null): EffectiveGrafanaSettings
    {
        return $this->effectiveGrafanaSettingsOverRepository($this->repository(), cache: $cache);
    }

    private function settings(EffectiveGrafanaSettings $effective): GrafanaSettings
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof GrafanaSettingsEntity) {
                $this->stored = $entity;
            }
        });

        return new GrafanaSettings(
            $this->repository(),
            $entityManager,
            GrafanaApiKeyCiphers::withTestSecret(),
            $effective,
            new GrafanaEnvDefaults('', ''),
        );
    }

    private function repository(): StoredGrafanaSettingsInterface
    {
        $repository = $this->createStub(StoredGrafanaSettingsInterface::class);
        $repository->method('findSingleton')->willReturnCallback(fn (): ?GrafanaSettingsEntity => $this->stored);

        return $repository;
    }
}
