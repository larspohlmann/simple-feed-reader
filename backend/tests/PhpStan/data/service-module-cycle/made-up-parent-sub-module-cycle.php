<?php

declare(strict_types=1);

// Fixtures for ServiceModuleCycleRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Atlas {
    final class FixtureAtlas
    {
        public function projection(): string
        {
            return \App\Service\Atlas\Maps\FixtureMercator::class;
        }
    }
}

namespace App\Service\Atlas\Maps {
    use App\Service\Atlas\FixtureAtlas;

    final class FixtureMercator
    {
        public function __construct(public FixtureAtlas $atlas)
        {
        }
    }
}
