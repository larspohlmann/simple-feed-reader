<?php

declare(strict_types=1);

// Fixtures for ServiceModuleCycleRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Ai {
    final class FixtureConnection
    {
    }
}

namespace App\Service\Recommendation {
    use App\Service\Ai\FixtureConnection;

    final class FixtureTickPhases
    {
        public function __construct(public FixtureConnection $connection)
        {
        }

        public function engine(): string
        {
            return \App\Service\Ai\Llm\FixtureLlmEngine::class;
        }
    }
}

namespace App\Service\Ai\Llm {
    use App\Service\Recommendation\FixtureTickPhases;

    final class FixtureLlmEngine
    {
        public function __construct(public FixtureTickPhases $phases)
        {
        }
    }
}
