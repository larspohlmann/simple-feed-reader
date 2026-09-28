<?php

declare(strict_types=1);

namespace App\Tests\Service\Grafana\Model;

use App\Entity\GrafanaConnection;
use App\Entity\GrafanaSettings as GrafanaSettingsEntity;
use App\Entity\SealedSecret;
use App\Service\Grafana\Model\GrafanaSettingsSnapshotModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GrafanaSettingsSnapshotModelTest extends TestCase
{
    public function testItCarriesTheRowsConnectionTokenAndHint(): void
    {
        $connection = new GrafanaConnection(
            'https://loki.example/push',
            'tenant42',
            'https://grafana.example',
            'http://pyro:4040',
            true,
        );
        $entity = new GrafanaSettingsEntity();
        $entity->apply($connection, new SealedSecret('cipher', 'nonce', 'salt', 3), 'oken');

        $snapshot = GrafanaSettingsSnapshotModel::fromEntity($entity);

        self::assertEquals($connection, $snapshot->connection);
        self::assertEquals(new SealedSecret('cipher', 'nonce', 'salt', 3), $snapshot->sealedToken);
        self::assertSame('oken', $snapshot->tokenHint);
        self::assertTrue($snapshot->hasToken());
    }

    public function testAFreshRowHasNoToken(): void
    {
        $snapshot = GrafanaSettingsSnapshotModel::fromEntity(new GrafanaSettingsEntity());

        self::assertFalse($snapshot->hasToken());
        self::assertSame('', $snapshot->tokenHint);
        self::assertEquals(new GrafanaConnection(null, null, null, null, false), $snapshot->connection);
    }

    public function testARowWithATokenSurvivesTheCacheEntryRoundTrip(): void
    {
        $snapshot = new GrafanaSettingsSnapshotModel(
            new GrafanaConnection('https://loki.example/push', 'tenant42', 'https://grafana.example', null, true),
            new SealedSecret('cipher', 'nonce', 'salt', 3),
            'oken',
        );

        self::assertEquals($snapshot, GrafanaSettingsSnapshotModel::fromCacheEntryOrNull($snapshot->toCacheEntry()));
    }

    public function testATokenlessRowSurvivesTheCacheEntryRoundTripWithoutGainingAToken(): void
    {
        $snapshot = GrafanaSettingsSnapshotModel::fromEntity(new GrafanaSettingsEntity());

        $rebuilt = GrafanaSettingsSnapshotModel::fromCacheEntryOrNull($snapshot->toCacheEntry());

        self::assertEquals($snapshot, $rebuilt);
        self::assertFalse($rebuilt?->hasToken());
    }

    #[DataProvider('malformedEntries')]
    public function testAMalformedEntryIsRejectedAsAMiss(mixed $stored): void
    {
        self::assertNull(GrafanaSettingsSnapshotModel::fromCacheEntryOrNull($stored));
    }

    /** @return iterable<string, array{mixed}> */
    public static function malformedEntries(): iterable
    {
        $valid = [
            'lokiPushUrl' => null,
            'lokiUsername' => null,
            'grafanaUrl' => null,
            'pyroscopePushUrl' => null,
            'profilingEnabled' => true,
            'tokenCiphertext' => 'cipher',
            'tokenNonce' => 'nonce',
            'tokenSalt' => 'salt',
            'tokenKeyVersion' => 1,
            'tokenHint' => 'oken',
        ];

        yield 'not an array' => ['a string'];
        yield 'missing a key' => [array_diff_key($valid, ['tokenHint' => null])];
        yield 'profiling flag is not a bool' => [['profilingEnabled' => 1] + $valid];
        yield 'key version is not an int' => [['tokenKeyVersion' => '1'] + $valid];
        yield 'url override is not a string' => [['lokiPushUrl' => 42] + $valid];
        yield 'hint is not a string' => [['tokenHint' => null] + $valid];
    }
}
