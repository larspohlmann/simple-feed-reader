<?php

declare(strict_types=1);

namespace App\Tests\Service\Operations\Maintenance\MaintenanceSweep;

use App\Http\MaintenanceTickJson;
use App\Service\Operations\Logging\Loki\Model\LokiSpoolReportModel;
use App\Service\Operations\Maintenance\MaintenanceSweep\DigestsTickSweep;
use App\Service\Operations\Maintenance\MaintenanceSweep\HiddenProfilesTickSweep;
use App\Service\Operations\Maintenance\MaintenanceSweep\ImageVerificationTickSweep;
use App\Service\Operations\Maintenance\MaintenanceSweep\LinkCardsTickSweep;
use App\Service\Operations\Maintenance\MaintenanceSweep\MaintenanceSweepInterface;
use App\Service\Operations\Maintenance\MaintenanceSweep\PrivateFeedSyncTickSweep;
use App\Service\Operations\Maintenance\MaintenanceSweep\ProfileImagesTickSweep;
use App\Service\Operations\Maintenance\MaintenanceSweep\RecommendationsTickSweep;
use App\Service\Operations\Maintenance\MaintenanceSweep\SavedSearchMembershipsTickSweep;
use App\Service\Operations\Maintenance\MaintenanceSweep\TranscriptsTickSweep;
use App\Service\Operations\Maintenance\MaintenanceSweep\TrendingTickSweep;
use App\Service\Operations\Maintenance\MaintenanceTick;
use App\Service\Operations\Maintenance\Model\MaintenanceSweepsModel;
use App\Service\Operations\Maintenance\Model\MaintenanceTickReportModel;
use App\Service\Ingestion\Refresh\Model\RefreshReportModel;
use App\Tests\Support\TaggedItemOf;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MaintenanceSweepWiringTest extends KernelTestCase
{
    /** Memberships before the private-feed sync before Trending is #1666; a new sweep fails here until placed. */
    public function testTheTickRunsTheSweepsUnderTheirNamesInTheirDeclaredOrder(): void
    {
        self::assertSame([
            'recommendations' => RecommendationsTickSweep::class,
            'digests' => DigestsTickSweep::class,
            'imageVerification' => ImageVerificationTickSweep::class,
            'linkCards' => LinkCardsTickSweep::class,
            'transcripts' => TranscriptsTickSweep::class,
            'profileImages' => ProfileImagesTickSweep::class,
            'hiddenProfiles' => HiddenProfilesTickSweep::class,
            'savedSearchMemberships' => SavedSearchMembershipsTickSweep::class,
            'privateFeedSync' => PrivateFeedSyncTickSweep::class,
            'trending' => TrendingTickSweep::class,
        ], $this->wiredSweeps());
    }

    /** Reads priority() without the compiled container, which a changed priority does not rebuild under Infection. */
    public function testTheDeclaredPrioritiesGiveTheWiredOrder(): void
    {
        $declared = self::declaredImplementations();
        usort($declared, self::byPriority(...));

        self::assertSame(array_values($this->wiredSweeps()), $declared);
    }

    public function testEveryImplementationIsTagged(): void
    {
        $wired = array_values($this->wiredSweeps());
        sort($wired);

        self::assertSame(self::declaredImplementations(), $wired);
    }

    public function testEachSweepIsKeyedByItsOwnName(): void
    {
        foreach ($this->wiredSweeps() as $name => $class) {
            self::assertSame($name, TaggedItemOf::index($class));
        }
    }

    public function testATickJsonMapperClaimsEverySweepsReport(): void
    {
        $idleReports = array_map(
            static fn (MaintenanceSweepInterface $sweep): object => $sweep->idleReport(),
            $this->wiredSweepServices(),
        );
        $json = self::getContainer()->get(MaintenanceTickJson::class);
        self::assertInstanceOf(MaintenanceTickJson::class, $json);

        $body = $json->report(new MaintenanceTickReportModel(
            RefreshReportModel::finished(0, 0, 0, 0, 0, 0, 0, 0),
            MaintenanceSweepsModel::ran($idleReports),
            new LokiSpoolReportModel(0, 0),
        ));

        self::assertSame(['refresh', ...array_keys($idleReports), 'logShipping'], array_keys($body));
    }

    /** @return array<string, class-string<MaintenanceSweepInterface>> */
    private function wiredSweeps(): array
    {
        return array_map(
            static fn (MaintenanceSweepInterface $sweep): string => $sweep::class,
            $this->wiredSweepServices(),
        );
    }

    /** @return array<string, MaintenanceSweepInterface> */
    private function wiredSweepServices(): array
    {
        $tick = self::getContainer()->get(MaintenanceTick::class);
        self::assertInstanceOf(MaintenanceTick::class, $tick);
        $sweeps = new \ReflectionProperty(MaintenanceTick::class, 'sweeps')->getValue($tick);
        self::assertIsIterable($sweeps);
        $wired = [];
        foreach ($sweeps as $name => $sweep) {
            self::assertIsString($name);
            self::assertInstanceOf(MaintenanceSweepInterface::class, $sweep);
            $wired[$name] = $sweep;
        }

        return $wired;
    }

    /**
     * @param class-string<MaintenanceSweepInterface> $left
     * @param class-string<MaintenanceSweepInterface> $right
     */
    private static function byPriority(string $left, string $right): int
    {
        return TaggedItemOf::priority($right) <=> TaggedItemOf::priority($left);
    }

    /** @return list<class-string<MaintenanceSweepInterface>> */
    private static function declaredImplementations(): array
    {
        $implementations = [];
        $folder = __DIR__ . '/../../../../src/Service/Operations/Maintenance/MaintenanceSweep';
        foreach (glob($folder . '/*.php') ?: [] as $path) {
            $class = 'App\\Service\\Operations\\Maintenance\\MaintenanceSweep\\' . basename($path, '.php');
            if (is_subclass_of($class, MaintenanceSweepInterface::class)) {
                $implementations[] = $class;
            }
        }
        sort($implementations);

        return $implementations;
    }
}
