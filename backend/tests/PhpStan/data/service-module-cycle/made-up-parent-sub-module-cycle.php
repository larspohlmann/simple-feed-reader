<?php

declare(strict_types=1);

// Fixtures for ServiceModuleCycleRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Fixture\Atlas {
    final class FixtureAtlas
    {
        public function projection(): string
        {
            return \App\Service\Fixture\Atlas\Maps\FixtureMercator::class;
        }
    }
}

namespace App\Service\Fixture\Atlas\Maps {
    use App\Service\Fixture\Atlas\FixtureAtlas;

    final class FixtureMercator
    {
        public function __construct(public FixtureAtlas $atlas)
        {
        }
    }
}
