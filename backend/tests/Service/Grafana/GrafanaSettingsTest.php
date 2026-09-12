<?php

declare(strict_types=1);

namespace App\Tests\Service\Grafana;

use App\Dto\Admin\GrafanaSettingsRequest;
use App\Entity\GrafanaSettings as GrafanaSettingsEntity;
use App\Repository\GrafanaSettingsRepository;
use App\Service\Crypto\InstanceSecretCipher;
use App\Service\Grafana\Crypto\GrafanaApiKeyCipher;
use App\Service\Grafana\GrafanaConnection;
use App\Service\Grafana\GrafanaEnvDefaults;
use App\Service\Grafana\GrafanaSettings;
use App\Service\Profiling\NullProfileSampler;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class GrafanaSettingsTest extends TestCase
{
    private const SECRET = 'test-master-secret-at-least-32-chars-long!!';

    public function testUpdateThenViewStoresOverridesAndHidesTheToken(): void
    {
        $settings = $this->service($stored);

        $settings->update(new GrafanaSettingsRequest(
            lokiPushUrl: 'https://cloud.example/loki/push',
            lokiUsername: 'tenant42',
            grafanaUrl: 'https://cloud.example/grafana',
            token: 'glc_secrettoken',
        ));

        $view = $settings->view();
        self::assertSame('https://cloud.example/loki/push', $view['lokiPushUrl']);
        self::assertSame('tenant42', $view['lokiUsername']);
        self::assertSame('https://cloud.example/grafana', $view['grafanaUrl']);
        self::assertTrue($view['hasToken']);
        self::assertSame('oken', $view['tokenHint']);
        self::assertArrayNotHasKey('token', $view);
    }

    public function testBlankTokenKeepsTheStoredSecret(): void
    {
        $settings = $this->service($stored);
        $settings->update(new GrafanaSettingsRequest(grafanaUrl: 'https://a.example', token: 'glc_first'));

        $settings->update(new GrafanaSettingsRequest(grafanaUrl: 'https://b.example', token: null));

        $view = $settings->view();
        self::assertTrue($view['hasToken']);
        self::assertSame('https://b.example', $view['grafanaUrl']);
        self::assertSame('glc_first', $settings->lokiToken());
    }

    public function testRemoveTokenClearsTheStoredSecretButKeepsTheConnection(): void
    {
        $settings = $this->service($stored);
        $settings->update(new GrafanaSettingsRequest(grafanaUrl: 'https://a.example', token: 'glc_first'));
        self::assertTrue($settings->view()['hasToken']);

        $settings->update(new GrafanaSettingsRequest(grafanaUrl: 'https://other.example', removeToken: true));

        $view = $settings->view();
        self::assertFalse($view['hasToken']);
        self::assertSame('https://other.example', $view['grafanaUrl']);
        self::assertNull($settings->lokiToken());
    }

    public function testRemoveTokenWinsEvenWhenATokenIsAlsoSent(): void
    {
        $settings = $this->service($stored);
        $settings->update(new GrafanaSettingsRequest(token: 'glc_first'));

        $settings->update(new GrafanaSettingsRequest(token: 'glc_second', removeToken: true));

        self::assertFalse($settings->view()['hasToken']);
        self::assertNull($settings->lokiToken());
    }

    public function testEffectiveLokiPushUrlFallsBackToTheEnvDefaultWhenNoOverrideIsStored(): void
    {
        $settings = $this->service($stored, lokiPushUrlDefault: 'http://loki:3100/loki/api/v1/push');

        self::assertSame('http://loki:3100/loki/api/v1/push', $settings->effectiveLokiPushUrl());
    }

    public function testEffectiveLokiPushUrlPrefersTheStoredOverrideOverTheEnvDefault(): void
    {
        $settings = $this->service($stored, lokiPushUrlDefault: 'http://loki:3100/loki/api/v1/push');
        $settings->update(new GrafanaSettingsRequest(lokiPushUrl: 'https://cloud.example/loki/push'));

        self::assertSame('https://cloud.example/loki/push', $settings->effectiveLokiPushUrl());
    }

    public function testEffectiveLokiPushUrlIsNullWhenNeitherOverrideNorDefaultIsConfigured(): void
    {
        $settings = $this->service($stored, lokiPushUrlDefault: '');

        self::assertNull($settings->effectiveLokiPushUrl());
    }

    public function testEffectivePyroscopePushUrlFallsBackToTheEnvDefaultWhenNoOverrideIsStored(): void
    {
        $settings = $this->service($stored, pyroscopePushUrlDefault: 'http://pyroscope:4040');

        self::assertSame('http://pyroscope:4040', $settings->effectivePyroscopePushUrl());
    }

    public function testEffectivePyroscopePushUrlPrefersTheStoredOverrideOverTheEnvDefault(): void
    {
        $settings = $this->service($stored, pyroscopePushUrlDefault: 'http://pyroscope:4040');
        $settings->update(new GrafanaSettingsRequest(pyroscopePushUrl: 'http://custom:4040'));

        self::assertSame('http://custom:4040', $settings->effectivePyroscopePushUrl());
    }

    public function testEffectivePyroscopePushUrlIsNullWhenNeitherOverrideNorDefaultIsConfigured(): void
    {
        $settings = $this->service($stored, pyroscopePushUrlDefault: '');

        self::assertNull($settings->effectivePyroscopePushUrl());
    }

    public function testProfilingEnabledReflectsTheStoredRow(): void
    {
        $settings = $this->service($stored);
        self::assertFalse($settings->profilingEnabled());

        $settings->update(new GrafanaSettingsRequest(profilingEnabled: true));

        self::assertTrue($settings->profilingEnabled());
    }

    public function testBlankUsernameClearsTheStoredOverrideAndItStartsNull(): void
    {
        $settings = $this->service($stored);
        self::assertNull($settings->lokiUsername());

        $settings->update(new GrafanaSettingsRequest(lokiUsername: 'tenant42'));
        $settings->update(new GrafanaSettingsRequest(lokiUsername: ''));

        self::assertNull($settings->lokiUsername());
    }

    /**
     * A Loki flush resolves pushUrl, username and token in a row (#983 review
     * finding 1): the singleton read must be memoised so that costs one query,
     * not three.
     */
    public function testResolvingPushUrlUsernameAndTokenTogetherQueriesTheRepositoryOnce(): void
    {
        $entity = new GrafanaSettingsEntity();
        $entity->apply(
            new GrafanaConnection('https://cloud.example/loki/push', 'tenant42', null, null, false),
            (new GrafanaApiKeyCipher(new InstanceSecretCipher(self::SECRET)))->seal('glc_secrettoken'),
            'oken',
        );

        $repository = $this->createMock(GrafanaSettingsRepository::class);
        $repository->expects(self::once())->method('findSingleton')->willReturn($entity);

        $settings = new GrafanaSettings(
            $repository,
            $this->createStub(EntityManagerInterface::class),
            new GrafanaApiKeyCipher(new InstanceSecretCipher(self::SECRET)),
            new GrafanaEnvDefaults('', '', ''),
            new NullProfileSampler(),
        );

        self::assertSame('https://cloud.example/loki/push', $settings->effectiveLokiPushUrl());
        self::assertSame('tenant42', $settings->lokiUsername());
        self::assertSame('glc_secrettoken', $settings->lokiToken());
    }

    public function testRefreshMakesTheNextReadSeeAChangedRow(): void
    {
        $original = new GrafanaSettingsEntity();
        $original->applyWithoutToken(new GrafanaConnection(null, null, null, null, false));

        $changed = new GrafanaSettingsEntity();
        $changed->applyWithoutToken(new GrafanaConnection(null, null, null, null, true));

        $repository = $this->createMock(GrafanaSettingsRepository::class);
        $repository->expects(self::exactly(2))
            ->method('findSingleton')
            ->willReturnOnConsecutiveCalls($original, $changed);

        $settings = new GrafanaSettings(
            $repository,
            $this->createStub(EntityManagerInterface::class),
            new GrafanaApiKeyCipher(new InstanceSecretCipher(self::SECRET)),
            new GrafanaEnvDefaults('', '', ''),
            new NullProfileSampler(),
        );

        self::assertFalse($settings->profilingEnabled());
        self::assertFalse($settings->profilingEnabled());

        $settings->refresh();

        self::assertTrue($settings->profilingEnabled());
    }

    public function testUpdateFlushesTheEntityManager(): void
    {
        $repository = $this->createStub(GrafanaSettingsRepository::class);
        $repository->method('findSingleton')->willReturn(new GrafanaSettingsEntity());

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $cipher = new GrafanaApiKeyCipher(new InstanceSecretCipher(self::SECRET));
        $settings = new GrafanaSettings(
            $repository,
            $em,
            $cipher,
            new GrafanaEnvDefaults('', '', ''),
            new NullProfileSampler(),
        );

        $settings->update(new GrafanaSettingsRequest(grafanaUrl: 'https://a.example'));
    }

    /** @param GrafanaSettingsEntity|null $stored captured by reference for the fake repo. */
    private function service(
        ?GrafanaSettingsEntity &$stored,
        string $lokiPushUrlDefault = '',
        string $grafanaUrlDefault = '',
        string $pyroscopePushUrlDefault = '',
    ): GrafanaSettings {
        $stored = null;
        $repository = $this->createStub(GrafanaSettingsRepository::class);
        $repository->method('findSingleton')->willReturnCallback(static function () use (&$stored) {
            return $stored;
        });

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$stored): void {
            if ($entity instanceof GrafanaSettingsEntity) {
                $stored = $entity;
            }
        });

        $cipher = new GrafanaApiKeyCipher(new InstanceSecretCipher(self::SECRET));
        $defaults = new GrafanaEnvDefaults($lokiPushUrlDefault, $grafanaUrlDefault, $pyroscopePushUrlDefault);

        return new GrafanaSettings($repository, $em, $cipher, $defaults, new NullProfileSampler());
    }
}
