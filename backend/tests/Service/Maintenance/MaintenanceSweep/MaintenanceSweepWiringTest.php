<?php

declare(strict_types=1);

namespace App\Tests\Service\Maintenance\MaintenanceSweep;

use App\Service\Maintenance\MaintenanceSweep\DigestsTickSweep;
use App\Service\Maintenance\MaintenanceSweep\HiddenProfilesTickSweep;
use App\Service\Maintenance\MaintenanceSweep\ImageVerificationTickSweep;
use App\Service\Maintenance\MaintenanceSweep\LinkCardsTickSweep;
use App\Service\Maintenance\MaintenanceSweep\MaintenanceSweepInterface;
use App\Service\Maintenance\MaintenanceSweep\PrivateFeedSyncTickSweep;
use App\Service\Maintenance\MaintenanceSweep\ProfileImagesTickSweep;
use App\Service\Maintenance\MaintenanceSweep\RecommendationsTickSweep;
use App\Service\Maintenance\MaintenanceSweep\SavedSearchMembershipsTickSweep;
use App\Service\Maintenance\MaintenanceSweep\TranscriptsTickSweep;
use App\Service\Maintenance\MaintenanceSweep\TrendingTickSweep;
use App\Service\Maintenance\MaintenanceTick;
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

    public function testEveryImplementationIsTagged(): void
    {
        $wired = array_values($this->wiredSweeps());
        sort($wired);

        self::assertSame(self::declaredImplementations(), $wired);
    }

    public function testEachSweepIsKeyedByItsOwnName(): void
    {
        foreach ($this->wiredSweeps() as $name => $class) {
            self::assertSame($name, $class::name());
        }
    }

    /** @return array<string, class-string<MaintenanceSweepInterface>> */
    private function wiredSweeps(): array
    {
        $tick = self::getContainer()->get(MaintenanceTick::class);
        self::assertInstanceOf(MaintenanceTick::class, $tick);
        $sweeps = new \ReflectionProperty(MaintenanceTick::class, 'sweeps')->getValue($tick);
        self::assertIsIterable($sweeps);
        $wired = [];
        foreach ($sweeps as $name => $sweep) {
            self::assertIsString($name);
            self::assertInstanceOf(MaintenanceSweepInterface::class, $sweep);
            $wired[$name] = $sweep::class;
        }

        return $wired;
    }

    /** @return list<class-string<MaintenanceSweepInterface>> */
    private static function declaredImplementations(): array
    {
        $implementations = [];
        $folder = __DIR__ . '/../../../../src/Service/Maintenance/MaintenanceSweep';
        foreach (glob($folder . '/*.php') ?: [] as $path) {
            $class = 'App\\Service\\Maintenance\\MaintenanceSweep\\' . basename($path, '.php');
            if (is_subclass_of($class, MaintenanceSweepInterface::class)) {
                $implementations[] = $class;
            }
        }
        sort($implementations);

        return $implementations;
    }
}
