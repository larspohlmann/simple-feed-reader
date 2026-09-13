<?php

declare(strict_types=1);

namespace App\Tests\Service\Grafana;

use App\Entity\GrafanaSettings as GrafanaSettingsEntity;
use App\Service\Crypto\SealedSecret;
use App\Service\Grafana\GrafanaConnection;
use App\Service\Grafana\GrafanaSettingsSnapshot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GrafanaSettingsSnapshotTest extends TestCase
{
    public function testARowWithATokenSurvivesTheArrayRoundTrip(): void
    {
        $entity = new GrafanaSettingsEntity();
        $entity->apply(
            new GrafanaConnection(
                'https://loki.example/push',
                'tenant42',
                'https://grafana.example',
                'http://pyro:4040',
                true,
            ),
            new SealedSecret('cipher', 'nonce', 'salt', 3),
            'oken',
        );

        $rebuilt = self::roundTrip($entity);

        self::assertNotNull($rebuilt);
        self::assertSame('https://loki.example/push', $rebuilt->getLokiPushUrlOverride());
        self::assertSame('tenant42', $rebuilt->getLokiUsername());
        self::assertSame('https://grafana.example', $rebuilt->getGrafanaUrlOverride());
        self::assertSame('http://pyro:4040', $rebuilt->getPyroscopePushUrlOverride());
        self::assertTrue($rebuilt->isProfilingEnabled());
        self::assertTrue($rebuilt->hasToken());
        self::assertSame('oken', $rebuilt->getTokenHint());
        self::assertEquals(new SealedSecret('cipher', 'nonce', 'salt', 3), $rebuilt->getSealedToken());
    }

    public function testATokenlessRowSurvivesTheArrayRoundTripWithoutGainingAToken(): void
    {
        $entity = new GrafanaSettingsEntity();
        $entity->applyWithoutToken(new GrafanaConnection(null, null, null, null, false));

        $rebuilt = self::roundTrip($entity);

        self::assertNotNull($rebuilt);
        self::assertFalse($rebuilt->hasToken());
        self::assertFalse($rebuilt->isProfilingEnabled());
        self::assertNull($rebuilt->getLokiPushUrlOverride());
    }

    #[DataProvider('malformedEntries')]
    public function testAMalformedEntryIsRejectedAsAMiss(mixed $stored): void
    {
        self::assertNull(GrafanaSettingsSnapshot::fromArrayOrNull($stored));
    }

    private static function roundTrip(GrafanaSettingsEntity $entity): ?GrafanaSettingsEntity
    {
        $stored = GrafanaSettingsSnapshot::fromEntity($entity)->toArray();

        return GrafanaSettingsSnapshot::fromArrayOrNull($stored)?->toEntity();
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
