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

    public function testWithNoRowTheLokiPushUrlFallsBackToItsEnvDefault(): void
    {
        $settings = $this->effectiveGrafanaSettingsOver(null, new GrafanaEnvDefaults(self::LOKI_DEFAULT, ''));

        self::assertSame(self::LOKI_DEFAULT, $settings->effectiveLokiPushUrl());
    }

    public function testAStoredOverrideWinsOverTheEnvDefault(): void
    {
        $row = $this->row(new GrafanaConnection('https://cloud.example/loki/push', null, null));

        $settings = $this->effectiveGrafanaSettingsOver($row, new GrafanaEnvDefaults(self::LOKI_DEFAULT, ''));

        self::assertSame('https://cloud.example/loki/push', $settings->effectiveLokiPushUrl());
    }

    public function testAUrlIsNullWhenNeitherOverrideNorDefaultIsConfigured(): void
    {
        $settings = $this->effectiveGrafanaSettingsOver(null);

        self::assertNull($settings->effectiveLokiPushUrl());
    }

    public function testTheLokiUsernameComesFromTheRow(): void
    {
        $row = $this->row(new GrafanaConnection(null, 'tenant42', null));
        $settings = $this->effectiveGrafanaSettingsOver($row);

        self::assertSame('tenant42', $settings->lokiUsername());
    }

    public function testWithNoRowThereIsNoUsernameOrToken(): void
    {
        $settings = $this->effectiveGrafanaSettingsOver(null);

        self::assertNull($settings->lokiUsername());
        self::assertNull($settings->lokiToken());
    }

    public function testTheStoredTokenIsOpened(): void
    {
        $settings = $this->effectiveGrafanaSettingsOver($this->rowWithToken('glc_secrettoken'));

        self::assertSame('glc_secrettoken', $settings->lokiToken());
    }

    /** A Loki flush reads push URL, username and token in a row: that must cost one lookup, not three. */
    public function testResolvingPushUrlUsernameAndTokenTogetherQueriesTheRepositoryOnce(): void
    {
        $repository = $this->createMock(StoredGrafanaSettingsInterface::class);
        $repository->expects($this->once())->method('findSingleton')->willReturn($this->rowWithToken('glc_secret'));
        $settings = $this->effectiveGrafanaSettingsOverRepository($repository);

        self::assertSame('https://cloud.example/loki/push', $settings->effectiveLokiPushUrl());
        self::assertSame('tenant42', $settings->lokiUsername());
        self::assertSame('glc_secret', $settings->lokiToken());
    }

    /** The worker resets services after every message; a warm shared pool must keep that off the database. */
    public function testResetAloneKeepsServingTheCachedRowWithoutQueryingAgain(): void
    {
        $repository = $this->createMock(StoredGrafanaSettingsInterface::class);
        $repository->expects($this->once())->method('findSingleton')->willReturn(new GrafanaSettingsEntity());
        $settings = $this->effectiveGrafanaSettingsOverRepository($repository);

        $settings->lokiUsername();
        $settings->reset();
        $settings->lokiUsername();
    }

    public function testForgetStoredSendsTheNextReadBackToTheDatabase(): void
    {
        $repository = $this->createMock(StoredGrafanaSettingsInterface::class);
        $repository->expects($this->exactly(2))->method('findSingleton')->willReturn(new GrafanaSettingsEntity());
        $settings = $this->effectiveGrafanaSettingsOverRepository($repository);

        $settings->lokiUsername();
        $settings->forgetStored();
        $settings->lokiUsername();
    }

    public function testTheMemoKeepsServingTheOldValueUntilResetRereadsTheInvalidatedCache(): void
    {
        $cache = new GrafanaSettingsCache(new ArrayAdapter());
        $repositoryBeforeSave = $this->createStub(StoredGrafanaSettingsInterface::class);
        $repositoryBeforeSave->method('findSingleton')->willReturn(null);
        $worker = $this->effectiveGrafanaSettingsOverRepository($repositoryBeforeSave, cache: $cache);

        self::assertNull($worker->lokiUsername());

        $repositoryAfterSave = $this->createStub(StoredGrafanaSettingsInterface::class);
        $repositoryAfterSave->method('findSingleton')
            ->willReturn($this->row(new GrafanaConnection(null, 'tenant42', null)));
        $adminSideAfterSave = $this->effectiveGrafanaSettingsOverRepository($repositoryAfterSave, cache: $cache);
        $adminSideAfterSave->forgetStored();
        self::assertSame('tenant42', $adminSideAfterSave->lokiUsername());

        self::assertNull($worker->lokiUsername());

        $worker->reset();

        self::assertSame('tenant42', $worker->lokiUsername());
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
            new GrafanaConnection('https://cloud.example/loki/push', 'tenant42', null),
            GrafanaApiKeyCiphers::withTestSecret()->seal($token),
            substr($token, -4),
        );

        return $row;
    }
}
