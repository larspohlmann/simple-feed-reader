<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\InclusionChange;
use App\Entity\InclusionSwitches;
use App\Enum\InclusionSurface;
use PHPUnit\Framework\TestCase;

final class InclusionSwitchesTest extends TestCase
{
    public function testEverySurfaceStartsSwitchedIn(): void
    {
        $switches = new InclusionSwitches();

        foreach (InclusionSurface::cases() as $surface) {
            self::assertTrue($switches->isIncludedIn($surface), $surface->name);
        }
    }

    public function testEachSwitchIsWrittenToItsOwnField(): void
    {
        $switches = new InclusionSwitches()->changedBy(new InclusionChange(false, true, false, false));

        self::assertFalse($switches->isIncludedIn(InclusionSurface::AllItems));
        self::assertTrue($switches->isIncludedIn(InclusionSurface::ForYou));
        self::assertFalse($switches->isIncludedIn(InclusionSurface::SavedSearches));
        self::assertFalse($switches->isIncludedIn(InclusionSurface::Trending));
    }

    public function testANullSwitchLeavesItsFieldAlone(): void
    {
        $switches = new InclusionSwitches(false, false, false, false)->changedBy(new InclusionChange());

        foreach (InclusionSurface::cases() as $surface) {
            self::assertFalse($switches->isIncludedIn($surface), $surface->name);
        }
    }

    public function testOnlyTheCarriedSwitchChanges(): void
    {
        $switches = new InclusionSwitches()->changedBy(new InclusionChange(forYou: false));

        self::assertTrue($switches->isIncludedIn(InclusionSurface::AllItems));
        self::assertFalse($switches->isIncludedIn(InclusionSurface::ForYou));
        self::assertTrue($switches->isIncludedIn(InclusionSurface::SavedSearches));
        self::assertTrue($switches->isIncludedIn(InclusionSurface::Trending));
    }

    public function testWithSwitchesOneSurfaceOnACopy(): void
    {
        $original = new InclusionSwitches();

        $switched = $original->with(InclusionSurface::Trending, false);

        self::assertFalse($switched->isIncludedIn(InclusionSurface::Trending));
        self::assertTrue($switched->isIncludedIn(InclusionSurface::AllItems));
        self::assertTrue($original->isIncludedIn(InclusionSurface::Trending));
    }
}
