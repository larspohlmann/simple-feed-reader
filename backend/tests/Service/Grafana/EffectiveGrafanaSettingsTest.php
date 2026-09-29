<?php

declare(strict_types=1);

namespace App\Tests\Service\Grafana;

use App\Entity\GrafanaConnection;
use App\Entity\GrafanaSettings as GrafanaSettingsEntity;
use App\Service\Grafana\GrafanaEnvDefaults;
use App\Service\Grafana\GrafanaSettingsCache;
use App\Service\Grafana\StoredGrafanaSettings\StoredGrafanaSettingsInterface;
use App\Tests\Support\BuildsEffectiveGrafanaSettings;
use App\Tests\Support\GrafanaApiKeyCiphers;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class EffectiveGrafanaSettingsTest extends TestCase
{
    use BuildsEffectiveGrafanaSettings;

    private const string LOKI_DEFAULT = 'http://loki:3100/loki/api/v1/push';
    private const string PYROSCOPE_DEFAULT = 'http://pyroscope:4040';

    public function testWithNoRowEachUrlFallsBackToItsEnvDefault(): void
    {
        $defaults = new GrafanaEnvDefaults(self::LOKI_DEFAULT, '', self::PYROSCOPE_DEFAULT);
        $settings = $this->effectiveGrafanaSettingsOver(null, $defaults);

        self::assertSame(self::LOKI_DEFAULT, $settings->effectiveLokiPushUrl());
        self::assertSame(self::PYROSCOPE_DEFAULT, $settings->effectivePyroscopePushUrl());
    }

    public function testAStoredOverrideWinsOverTheEnvDefault(): void
    {
        $row = $this->row(
            new GrafanaConnection('https://cloud.example/loki/push', null, null, 'http://custom:4040', false),
        );
        $defaults = new GrafanaEnvDefaults(self::LOKI_DEFAULT, '', self::PYROSCOPE_DEFAULT);

        $settings = $this->effectiveGrafanaSettingsOver($row, $defaults);

        self::assertSame('https://cloud.example/loki/push', $settings->effectiveLokiPushUrl());
        self::assertSame('http://custom:4040', $settings->effectivePyroscopePushUrl());
    }

    public function testAUrlIsNullWhenNeitherOverrideNorDefaultIsConfigured(): void
    {
        $settings = $this->effectiveGrafanaSettingsOver(null);

        self::assertNull($settings->effectiveLokiPushUrl());
        self::assertNull($settings->effectivePyroscopePushUrl());
    }

    public function testProfilingAndTheLokiUsernameComeFromTheRow(): void
    {
        $row = $this->row(new GrafanaConnection(null, 'tenant42', null, null, true));
        $settings = $this->effectiveGrafanaSettingsOver($row);

        self::assertTrue($settings->profilingEnabled());
        self::assertSame('tenant42', $settings->lokiUsername());
    }

    public function testWithNoRowProfilingIsOffAndThereIsNoUsernameOrToken(): void
    {
        $settings = $this->effectiveGrafanaSettingsOver(null);

        self::assertFalse($settings->profilingEnabled());
        self::assertNull($settings->lokiUsername());
        self::assertNull($settings->lokiToken());
    }

    public function testTheStoredTokenIsOpened(): void
    {
        $settings = $this->effectiveGrafanaSettingsOver($this->rowWithToken('glc_secrettoken'));

        self::assertSame('glc_secrettoken', $settings->lokiToken());
    }

    /** A Loki flush reads push URL, username and token in a row (#983): that must cost one lookup, not three. */
    public function testResolvingPushUrlUsernameAndTokenTogetherQueriesTheRepositoryOnce(): void
    {
        $repository = $this->createMock(StoredGrafanaSettingsInterface::class);
        $repository->expects($this->once())->method('findSingleton')->willReturn($this->rowWithToken('glc_secret'));
        $settings = $this->effectiveGrafanaSettingsOverRepository($repository);

        self::assertSame('https://cloud.example/loki/push', $settings->effectiveLokiPushUrl());
        self::assertSame('tenant42', $settings->lokiUsername());
        self::assertSame('glc_secret', $settings->lokiToken());
    }

    /** The worker calls refresh() every 30 s (#1012); a warm shared pool must keep that off the database. */
    public function testRefreshAloneKeepsServingTheCachedRowWithoutQueryingAgain(): void
    {
        $repository = $this->createMock(StoredGrafanaSettingsInterface::class);
        $repository->expects($this->once())->method('findSingleton')->willReturn(new GrafanaSettingsEntity());
        $settings = $this->effectiveGrafanaSettingsOverRepository($repository);

        $settings->profilingEnabled();
        $settings->refresh();
        $settings->profilingEnabled();
    }

    public function testForgetStoredSendsTheNextReadBackToTheDatabase(): void
    {
        $repository = $this->createMock(StoredGrafanaSettingsInterface::class);
        $repository->expects($this->exactly(2))->method('findSingleton')->willReturn(new GrafanaSettingsEntity());
        $settings = $this->effectiveGrafanaSettingsOverRepository($repository);

        $settings->profilingEnabled();
        $settings->forgetStored();
        $settings->profilingEnabled();
    }

    public function testTheMemoKeepsServingTheOldValueUntilRefreshRereadsTheInvalidatedCache(): void
    {
        $cache = new GrafanaSettingsCache(new ArrayAdapter());
        $repositoryBeforeSave = $this->createStub(StoredGrafanaSettingsInterface::class);
        $repositoryBeforeSave->method('findSingleton')->willReturn(null);
        $worker = $this->effectiveGrafanaSettingsOverRepository($repositoryBeforeSave, cache: $cache);

        self::assertFalse($worker->profilingEnabled());

        $repositoryAfterSave = $this->createStub(StoredGrafanaSettingsInterface::class);
        $repositoryAfterSave->method('findSingleton')
            ->willReturn($this->row(new GrafanaConnection(null, null, null, null, true)));
        $adminSideAfterSave = $this->effectiveGrafanaSettingsOverRepository($repositoryAfterSave, cache: $cache);
        $adminSideAfterSave->forgetStored();
        self::assertTrue($adminSideAfterSave->profilingEnabled());

        self::assertFalse($worker->profilingEnabled());

        $worker->refresh();

        self::assertTrue($worker->profilingEnabled());
    }

    private function row(GrafanaConnection $connection): GrafanaSettingsEntity
    {
        $row = new GrafanaSettingsEntity();
        $row->applyWithoutToken($connection);

        return $row;
    }

    private function rowWithToken(string $token): GrafanaSettingsEntity
    {
        $row = new GrafanaSettingsEntity();
        $row->apply(
            new GrafanaConnection('https://cloud.example/loki/push', 'tenant42', null, null, false),
            GrafanaApiKeyCiphers::withTestSecret()->seal($token),
            substr($token, -4),
        );

        return $row;
    }
}
