<?php

declare(strict_types=1);

// Fixtures for ServiceModuleCycleRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Atlas\Maps {
    final class FixtureMapShelf
    {
        public function tiles(): string
        {
            return \App\Service\Atlas\Maps\Tiles\FixtureTile::class;
        }
    }
}

namespace App\Service\Atlas\Maps\Tiles {
    use App\Service\Atlas\Maps\FixtureMapShelf;

    final class FixtureTile
    {
        public function __construct(public FixtureMapShelf $shelf)
        {
        }
    }
}
